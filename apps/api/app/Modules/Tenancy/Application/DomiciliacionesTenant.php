<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\DomiciliacionNoPermitida;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\ClientePasarelaTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Pasarelas\PasarelaConSuscripcion;
use App\Modules\Tenancy\Pasarelas\PasarelaDomiciliable;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Modules\Tenancy\Pasarelas\TarjetaGuardada;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pago automático (domiciliación) de membresías: el alumno autoriza su tarjeta en la
 * pasarela del negocio y cada renovación se cobra sola ({@see CobroRecurrenteTenant}).
 *
 * Dos formas, según la pasarela:
 * - tarjeta domiciliada ({@see PasarelaDomiciliable}, Stripe): el sistema cobra cada
 *   renovación. Una persona tiene UNA tarjeta por pasarela: si autoriza otra, pasa a
 *   cobrarse ahí todo lo domiciliado y la anterior se desliga;
 * - suscripción ({@see PasarelaConSuscripcion}, Mercado Pago y OpenPay): la pasarela
 *   cobra sola cada mes; una por membresía, pendiente hasta que el cliente la
 *   autoriza ({@see ConciliarSuscripcionTenant}).
 *
 * Cada membresía decide si se domicilia (se puede activar o quitar por separado).
 */
class DomiciliacionesTenant
{
    public function __construct(private readonly RegistroDePasarelasTenant $registro) {}

    /**
     * La pasarela en línea del negocio, si admite pagos automáticos.
     */
    public function proveedor(): ?string
    {
        $proveedor = $this->registro->enLinea();

        if ($proveedor === null) {
            return null;
        }
        $pasarela = $this->registro->resolver($proveedor);

        return $pasarela instanceof PasarelaDomiciliable || $pasarela instanceof PasarelaConSuscripcion
            ? $proveedor
            : null;
    }

    /**
     * La tarjeta que comparten los pagos automáticos de la persona, si la pasarela
     * del negocio funciona así (Stripe). Con suscripciones cada membresía tiene la
     * suya.
     */
    public function tarjetaCompartida(PersonaTenant $persona): ?DomiciliacionTenant
    {
        $proveedor = $this->proveedor();

        return $proveedor !== null && $this->registro->resolver($proveedor) instanceof PasarelaDomiciliable
            ? $this->tarjetaDe($persona, $proveedor)
            : null;
    }

    /**
     * ¿La membresía se renueva (y se puede domiciliar)?
     */
    public static function renovable(AcuerdoTenant $acuerdo): bool
    {
        return $acuerdo->proxima_cobro_en !== null && $acuerdo->estado !== EstadoAcuerdo::Cancelado;
    }

    /**
     * La tarjeta con la que se cobran los pagos automáticos de la persona.
     */
    public function tarjetaDe(PersonaTenant $persona, string $proveedor): ?DomiciliacionTenant
    {
        return DomiciliacionTenant::query()
            ->where('persona_id', $persona->getKey())
            ->where('proveedor', $proveedor)
            ->where('estado', DomiciliacionTenant::ACTIVA)
            ->whereNotNull('metodo_externo')
            ->latest('id')
            ->first();
    }

    /**
     * Activa el pago automático de una membresía. Si la persona ya autorizó una
     * tarjeta, queda activo al momento; si no, devuelve la página de la pasarela para
     * autorizarla (al terminar, el webhook la registra y lo activa). Con suscripción,
     * la pasarela puede pedir primero capturar la tarjeta (`formulario`).
     *
     * @param  array<string, string>  $datos  la tarjeta tokenizada en el navegador (OpenPay)
     * @return array{estado: string, url?: string, formulario?: array<string, mixed>}
     */
    public function activar(AcuerdoTenant $acuerdo, ?string $retorno, array $datos = []): array
    {
        $proveedor = $this->proveedor()
            ?? throw new DomiciliacionNoPermitida('Este negocio todavía no cobra en línea con tarjeta.');
        if (! self::renovable($acuerdo)) {
            throw new DomiciliacionNoPermitida('Esta membresía no se renueva; no necesita pago automático.');
        }
        $persona = $acuerdo->persona ?? throw new DomiciliacionNoPermitida('La membresía no tiene titular.');

        $vigente = $acuerdo->domiciliacion()->first();
        if ($vigente instanceof DomiciliacionTenant && $vigente->proveedor === $proveedor) {
            return ['estado' => 'activa'];
        }

        $pasarela = $this->registro->resolver($proveedor);
        if ($pasarela instanceof PasarelaConSuscripcion) {
            return $this->suscribir($acuerdo, $proveedor, $pasarela, $retorno, $datos);
        }

        $tarjeta = $this->tarjetaDe($persona, $proveedor);
        if ($tarjeta instanceof DomiciliacionTenant) {
            $this->domiciliar($acuerdo, $proveedor, new TarjetaGuardada(
                (string) $tarjeta->metodo_externo, $tarjeta->marca, $tarjeta->ultimos4, $tarjeta->expira_mes, $tarjeta->expira_anio,
            ));

            return ['estado' => 'activa'];
        }

        $checkout = $this->pasarela($proveedor)->iniciarGuardado(
            $persona,
            $retorno,
            ['persona' => (string) $persona->ulid, 'acuerdos' => (string) $acuerdo->ulid],
            $this->registro->llaves($proveedor),
        );

        return ['estado' => 'redirect', 'url' => $checkout['url']];
    }

    /**
     * Página de la pasarela para autorizar otra tarjeta: al terminar, todo lo
     * domiciliado se cobra ahí.
     *
     * @return array{url: string}
     */
    public function cambiarTarjeta(PersonaTenant $persona, ?string $retorno): array
    {
        $proveedor = $this->proveedor()
            ?? throw new DomiciliacionNoPermitida('Este negocio todavía no cobra en línea con tarjeta.');

        $checkout = $this->pasarela($proveedor)->iniciarGuardado(
            $persona,
            $retorno,
            ['persona' => (string) $persona->ulid, 'acuerdos' => ''],
            $this->registro->llaves($proveedor),
        );

        return ['url' => $checkout['url']];
    }

    /**
     * La pasarela confirmó una tarjeta autorizada: pasa a ser la de todos los pagos
     * automáticos de la persona y se activan las membresías indicadas (suyas y que se
     * renuevan). Idempotente. La tarjeta anterior se desliga.
     *
     * @param  list<int>  $acuerdoIds
     */
    public function registrarTarjeta(PersonaTenant $persona, string $proveedor, TarjetaGuardada $tarjeta, array $acuerdoIds): void
    {
        $anteriores = DB::connection('tenant')->transaction(function () use ($persona, $proveedor, $tarjeta, $acuerdoIds): array {
            $activas = DomiciliacionTenant::query()
                ->where('persona_id', $persona->getKey())
                ->where('proveedor', $proveedor)
                ->where('estado', DomiciliacionTenant::ACTIVA)
                ->lockForUpdate()
                ->get();

            $anteriores = $activas
                ->pluck('metodo_externo')
                ->filter(static fn (?string $metodo): bool => $metodo !== null && $metodo !== $tarjeta->metodo)
                ->unique()
                ->values()
                ->all();

            foreach ($activas as $domiciliacion) {
                $domiciliacion->update([...$tarjeta->atributos(), 'ultimo_error' => null, 'ultimo_error_en' => null]);
            }

            $acuerdos = AcuerdoTenant::query()
                ->whereIn('id', $acuerdoIds)
                ->where('persona_id', $persona->getKey())
                ->get();
            foreach ($acuerdos as $acuerdo) {
                if (self::renovable($acuerdo)) {
                    $this->domiciliar($acuerdo, $proveedor, $tarjeta);
                }
            }

            return $anteriores;
        });

        foreach ($anteriores as $anterior) {
            $this->olvidar((int) $persona->getKey(), $proveedor, (string) $anterior);
        }
    }

    /**
     * La pasarela confirmó que el cliente autorizó la suscripción: queda como el pago
     * automático de la membresía (con la tarjeta, si la pasarela la informa).
     */
    public function activarSuscripcion(DomiciliacionTenant $domiciliacion, ?TarjetaGuardada $tarjeta): void
    {
        DB::connection('tenant')->transaction(function () use ($domiciliacion, $tarjeta): void {
            DomiciliacionTenant::query()
                ->where('acuerdo_id', $domiciliacion->acuerdo_id)
                ->whereKeyNot($domiciliacion->getKey())
                ->whereIn('estado', [DomiciliacionTenant::ACTIVA, DomiciliacionTenant::PENDIENTE])
                ->update(['estado' => DomiciliacionTenant::CANCELADA, 'cancelada_en' => Carbon::now()]);

            $domiciliacion->update([
                ...($tarjeta instanceof TarjetaGuardada && (string) $domiciliacion->metodo_externo === '' ? $tarjeta->atributos() : []),
                'estado' => DomiciliacionTenant::ACTIVA,
                'activada_en' => Carbon::now(),
                'ultimo_error' => null,
                'ultimo_error_en' => null,
            ]);
        });
    }

    /**
     * Quita el pago automático de la membresía: la renovación vuelve a avisarse para
     * pagarla a mano. Una suscripción se cancela en la pasarela; una tarjeta que ya no
     * se usa en nada, se desliga.
     */
    public function desactivar(AcuerdoTenant $acuerdo): void
    {
        $domiciliaciones = DomiciliacionTenant::query()
            ->where('acuerdo_id', $acuerdo->getKey())
            ->whereIn('estado', [DomiciliacionTenant::ACTIVA, DomiciliacionTenant::PENDIENTE])
            ->get();

        foreach ($domiciliaciones as $domiciliacion) {
            $this->cerrar($domiciliacion);
        }
    }

    /**
     * La membresía se pausa. Una suscripción la seguiría cobrando la pasarela: se
     * cancela (al reanudar, el alumno la vuelve a activar). Con tarjeta domiciliada no
     * hace falta: una membresía en pausa no se cobra.
     */
    public function alPausar(AcuerdoTenant $acuerdo): void
    {
        $domiciliaciones = DomiciliacionTenant::query()
            ->where('acuerdo_id', $acuerdo->getKey())
            ->whereIn('estado', [DomiciliacionTenant::ACTIVA, DomiciliacionTenant::PENDIENTE])
            ->get();

        foreach ($domiciliaciones as $domiciliacion) {
            if ($this->registro->resolver($domiciliacion->proveedor) instanceof PasarelaConSuscripcion) {
                $this->cerrar($domiciliacion);
            }
        }
    }

    private function cerrar(DomiciliacionTenant $domiciliacion): void
    {
        $domiciliacion->update(['estado' => DomiciliacionTenant::CANCELADA, 'cancelada_en' => Carbon::now()]);

        $pasarela = $this->registro->resolver($domiciliacion->proveedor);
        if ($pasarela instanceof PasarelaConSuscripcion) {
            try {
                $pasarela->cancelarSuscripcion($domiciliacion, $this->registro->llaves($domiciliacion->proveedor));
            } catch (Throwable $e) {
                report($e);
            }

            return;
        }

        $this->olvidar((int) $domiciliacion->persona_id, $domiciliacion->proveedor, (string) $domiciliacion->metodo_externo);
    }

    /**
     * Suscribe la membresía en la pasarela: una domiciliación pendiente que se activa
     * cuando la pasarela confirma (o al momento, si ya quedó).
     *
     * @param  array<string, string>  $datos
     * @return array{estado: string, url?: string, formulario?: array<string, mixed>}
     */
    private function suscribir(AcuerdoTenant $acuerdo, string $proveedor, PasarelaConSuscripcion $pasarela, ?string $retorno, array $datos): array
    {
        $domiciliacion = DomiciliacionTenant::query()->firstOrCreate(
            ['acuerdo_id' => $acuerdo->getKey(), 'proveedor' => $proveedor, 'estado' => DomiciliacionTenant::PENDIENTE],
            ['persona_id' => $acuerdo->persona_id],
        );

        $resultado = $pasarela->suscribir($domiciliacion, $acuerdo, $retorno, $datos, $this->registro->llaves($proveedor));
        if ($resultado['estado'] === 'activa') {
            $this->activarSuscripcion($domiciliacion->refresh(), null);
        }

        return $resultado;
    }

    /**
     * Baja de la persona (derechos ARCO): se quitan todos sus pagos automáticos, se
     * desligan sus tarjetas y se olvida su cliente en la pasarela.
     */
    public function desactivarDePersona(PersonaTenant $persona): void
    {
        $vigentes = DomiciliacionTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereIn('estado', [DomiciliacionTenant::ACTIVA, DomiciliacionTenant::PENDIENTE])
            ->get();

        foreach ($vigentes as $domiciliacion) {
            $this->cerrar($domiciliacion);
        }

        ClientePasarelaTenant::query()->where('persona_id', $persona->getKey())->delete();
    }

    /**
     * Resultado del último cargo automático: si falló, el motivo se muestra al
     * cliente y al negocio hasta que un cargo pase.
     */
    public function registrarCargo(DomiciliacionTenant $domiciliacion, ?string $error): void
    {
        $domiciliacion->update([
            'ultimo_error' => $error,
            'ultimo_error_en' => $error !== null ? Carbon::now() : null,
        ]);
    }

    /**
     * Domicilia la membresía con la tarjeta dada (la deja como única activa).
     */
    private function domiciliar(AcuerdoTenant $acuerdo, string $proveedor, TarjetaGuardada $tarjeta): void
    {
        DomiciliacionTenant::query()
            ->where('acuerdo_id', $acuerdo->getKey())
            ->where('estado', DomiciliacionTenant::ACTIVA)
            ->where('proveedor', '!=', $proveedor)
            ->update(['estado' => DomiciliacionTenant::CANCELADA, 'cancelada_en' => Carbon::now()]);

        DomiciliacionTenant::query()->updateOrCreate(
            ['acuerdo_id' => $acuerdo->getKey(), 'proveedor' => $proveedor, 'estado' => DomiciliacionTenant::ACTIVA],
            [
                ...$tarjeta->atributos(),
                'persona_id' => $acuerdo->persona_id,
                'ultimo_error' => null,
                'ultimo_error_en' => null,
                'activada_en' => Carbon::now(),
            ],
        );
    }

    /**
     * Desliga la tarjeta en la pasarela si ya no la usa ninguna membresía activa. Si
     * la pasarela falla, se reporta sin romper la operación (la tarjeta ya no se usa
     * para cobrar de este lado).
     */
    private function olvidar(int $personaId, string $proveedor, string $metodo): void
    {
        $enUso = DomiciliacionTenant::query()
            ->where('persona_id', $personaId)
            ->where('proveedor', $proveedor)
            ->where('metodo_externo', $metodo)
            ->where('estado', DomiciliacionTenant::ACTIVA)
            ->exists();
        if ($enUso || $metodo === '') {
            return;
        }

        try {
            $pasarela = $this->registro->resolver($proveedor);
            if ($pasarela instanceof PasarelaDomiciliable) {
                $pasarela->olvidarTarjeta($metodo, $this->registro->llaves($proveedor));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function pasarela(string $proveedor): PasarelaDomiciliable
    {
        $pasarela = $this->registro->resolver($proveedor);
        if (! $pasarela instanceof PasarelaDomiciliable) {
            throw new DomiciliacionNoPermitida('La pasarela del negocio no admite pagos automáticos.');
        }

        return $pasarela;
    }
}
