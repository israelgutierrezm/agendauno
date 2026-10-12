<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\DomiciliacionNoPermitida;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\ClientePasarelaTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SesionTarjetaTenant;
use App\Modules\Tenancy\Models\TutelaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Pasarelas\PasarelaConSuscripcion;
use App\Modules\Tenancy\Pasarelas\PasarelaDomiciliable;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Modules\Tenancy\Pasarelas\TarjetaGuardada;
use App\Support\ReporteDeErrores;
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
 *
 * Quien paga (`persona_id` de la domiciliación) es el titular de la membresía o un
 * responsable con autorización vigente por él (familias, ADR 0129): la tarjeta y el
 * cliente en la pasarela son de quien paga; la membresía sigue siendo del participante.
 * Si la autorización se retira, esos pagos automáticos se quitan.
 *
 * Seguro (ADR 0134): quitar una suscripción la deja `cancelando` hasta que la pasarela
 * confirma ({@see CancelacionSuscripcionesTenant}); cada reemplazo (otra tarjeta, otro
 * pagador, otra pasarela) cancela la anterior allá también. La domiciliación guarda el
 * precio que el cliente autorizó: con ella se abre la renovación hasta que autorice
 * otro.
 */
class DomiciliacionesTenant
{
    public function __construct(
        private readonly RegistroDePasarelasTenant $registro,
        private readonly FamiliasTenant $familias,
        private readonly CancelacionSuscripcionesTenant $cancelaciones,
    ) {}

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
     * Activa el pago automático de una membresía. Si quien paga ya autorizó una
     * tarjeta, queda activo al momento; si no, devuelve la página de la pasarela para
     * autorizarla (al terminar, el webhook la registra y lo activa). Con suscripción,
     * la pasarela puede pedir primero capturar la tarjeta (`formulario`).
     *
     * Quien paga es el titular o, si se da, un responsable con autorización vigente por
     * él: con su tarjeta (ADR 0129).
     *
     * @param  array<string, string>  $datos  la tarjeta tokenizada en el navegador (OpenPay)
     * @return array{estado: string, url?: string, formulario?: array<string, mixed>}
     */
    public function activar(AcuerdoTenant $acuerdo, ?string $retorno, array $datos = [], ?PersonaTenant $pagador = null): array
    {
        if (app(MembresiasPorModalidadTenant::class)->enCitas()) {
            throw new DomiciliacionNoPermitida('En un negocio de citas no hay pago automático: los bonos se pagan al comprarlos.');
        }
        $proveedor = $this->proveedor()
            ?? throw new DomiciliacionNoPermitida('Este negocio todavía no cobra en línea con tarjeta.');
        if (! self::renovable($acuerdo)) {
            throw new DomiciliacionNoPermitida('Esta membresía no se renueva; no necesita pago automático.');
        }
        $pagador ??= $acuerdo->persona ?? throw new DomiciliacionNoPermitida('La membresía no tiene titular.');
        if (! $this->puedePagar($pagador, $acuerdo)) {
            throw new DomiciliacionNoPermitida('No tienes autorización vigente para pagar por esa persona.');
        }

        $vigente = $acuerdo->domiciliacion()->first();
        if ($vigente instanceof DomiciliacionTenant && $vigente->proveedor === $proveedor
            && (int) $vigente->persona_id === (int) $pagador->getKey()) {
            return ['estado' => 'activa'];
        }

        $pasarela = $this->registro->resolver($proveedor);
        if ($pasarela instanceof PasarelaConSuscripcion) {
            return $this->suscribir($acuerdo, $proveedor, $pasarela, $retorno, $datos, $pagador);
        }

        $tarjeta = $this->tarjetaDe($pagador, $proveedor);
        if ($tarjeta instanceof DomiciliacionTenant) {
            $this->domiciliar($acuerdo, $proveedor, new TarjetaGuardada(
                (string) $tarjeta->metodo_externo, $tarjeta->marca, $tarjeta->ultimos4, $tarjeta->expira_mes, $tarjeta->expira_anio,
            ), $pagador);

            return ['estado' => 'activa'];
        }

        $checkout = $this->pasarela($proveedor)->iniciarGuardado(
            $pagador,
            $retorno,
            ['persona' => (string) $pagador->ulid, 'acuerdos' => (string) $acuerdo->ulid],
            $this->registro->llaves($proveedor),
        );
        $this->recordarSesion($pagador, $proveedor, $checkout);

        return ['estado' => 'redirect', 'url' => $checkout['url']];
    }

    /**
     * Lo que el cliente autorizó en su pago automático vigente (ADR 0134): con eso se
     * abre la renovación. Null si no tiene pago automático, si es anterior a guardar el
     * precio, o si es de otra moneda que el plan.
     *
     * @return array{monto_minor: int, moneda: string}|null
     */
    public function precioContratado(AcuerdoTenant $acuerdo): ?array
    {
        $domiciliacion = $acuerdo->relationLoaded('domiciliacion') ? $acuerdo->domiciliacion : $acuerdo->domiciliacion()->first();
        $producto = $acuerdo->producto;
        if (! $domiciliacion instanceof DomiciliacionTenant || $domiciliacion->monto_minor === null
            || ! $producto instanceof ProductoTenant || (string) $domiciliacion->moneda !== (string) $producto->moneda) {
            return null;
        }

        return ['monto_minor' => (int) $domiciliacion->monto_minor, 'moneda' => (string) $domiciliacion->moneda];
    }

    /**
     * El precio del plan cambió y su pago automático sigue con el que autorizó: ¿cuál
     * es el nuevo? Null si no cambió (o no se sabe).
     */
    public function nuevoPrecio(AcuerdoTenant $acuerdo): ?int
    {
        $contratado = $this->precioContratado($acuerdo);
        $actual = $acuerdo->producto?->precio_minor;

        return $contratado !== null && $actual !== null && (int) $actual !== $contratado['monto_minor'] ? (int) $actual : null;
    }

    /**
     * El cliente autoriza el precio nuevo del plan para su pago automático. Con tarjeta
     * domiciliada (Stripe) se actualiza al momento; con suscripción se crea otra al
     * precio nuevo, que el cliente autoriza en la pasarela, y al quedar activa la
     * anterior se cancela (con confirmación).
     *
     * @param  array<string, string>  $datos  la tarjeta tokenizada en el navegador (OpenPay)
     * @return array{estado: string, url?: string, formulario?: array<string, mixed>}
     */
    public function autorizarNuevoPrecio(AcuerdoTenant $acuerdo, ?string $retorno, array $datos, PersonaTenant $pagador, ?Usuario $actor = null): array
    {
        $domiciliacion = $acuerdo->domiciliacion()->first()
            ?? throw new DomiciliacionNoPermitida('Esta membresía no tiene pago automático.');
        if ((int) $domiciliacion->persona_id !== (int) $pagador->getKey()) {
            throw new DomiciliacionNoPermitida('Solo quien paga puede autorizar el nuevo precio.');
        }
        $acuerdo->loadMissing('producto');
        $producto = $acuerdo->producto ?? throw new DomiciliacionNoPermitida('La membresía no tiene producto.');
        if ($this->nuevoPrecio($acuerdo) === null) {
            return ['estado' => 'activa'];
        }

        $pasarela = $this->registro->resolver($domiciliacion->proveedor);
        if ($pasarela instanceof PasarelaConSuscripcion) {
            return $this->suscribir($acuerdo, $domiciliacion->proveedor, $pasarela, $retorno, $datos, $pagador);
        }

        $antes = (int) $domiciliacion->monto_minor;
        $domiciliacion->update(['monto_minor' => (int) $producto->precio_minor, 'moneda' => (string) $producto->moneda]);
        app(RegistrarAuditoria::class)->registrar($actor, 'pago_automatico.precio_autorizado', 'acuerdo', (string) $acuerdo->ulid,
            ['monto_minor' => $antes], ['monto_minor' => (int) $producto->precio_minor]);

        return ['estado' => 'activa'];
    }

    /**
     * A nombre de quién se guarda la tarjeta al pagar una compra con pago automático:
     * de quien la pagó desde su cuenta, si es un responsable con la autorización vigente
     * por el comprador (p. ej. la renovación de su hijo, ADR 0129); si no, del comprador.
     */
    public function pagadorDeCompra(OrdenTenant $orden, ?int $usuarioQuePaga): ?PersonaTenant
    {
        $comprador = $orden->persona;
        if ($usuarioQuePaga === null || ! $comprador instanceof PersonaTenant) {
            return $comprador;
        }
        $quienPaga = PersonaTenant::query()->where('usuario_id', $usuarioQuePaga)->first();

        return $quienPaga instanceof PersonaTenant
            && $this->familias->participantesAutorizados($quienPaga)->contains('id', (int) $comprador->getKey())
            ? $quienPaga
            : $comprador;
    }

    /**
     * ¿Puede pagar esta persona la membresía? Es suya o de alguien a su cargo con la
     * autorización vigente (ADR 0129).
     */
    public function puedePagar(PersonaTenant $pagador, AcuerdoTenant $acuerdo): bool
    {
        return (int) $acuerdo->persona_id === (int) $pagador->getKey()
            || $this->familias->participantesAutorizados($pagador)->contains('id', (int) $acuerdo->persona_id);
    }

    /**
     * ¿Sigue pudiendo cobrarse a la tarjeta de quien paga? Si es un responsable y ya no
     * tiene la autorización (la retiró, el negocio apagó familias), no.
     */
    public function sigueAutorizada(DomiciliacionTenant $domiciliacion, AcuerdoTenant $acuerdo): bool
    {
        if ((int) $domiciliacion->persona_id === (int) $acuerdo->persona_id) {
            return true;
        }
        $pagador = $domiciliacion->persona;

        return $pagador instanceof PersonaTenant && $this->puedePagar($pagador, $acuerdo);
    }

    /**
     * Se retiró la autorización de un responsable: su tarjeta deja de pagar las
     * membresías de esa persona (vuelven a avisarse para pagarlas a mano).
     */
    public function alRetirarTutela(TutelaTenant $tutela): void
    {
        $domiciliaciones = DomiciliacionTenant::query()
            ->where('persona_id', $tutela->responsable_id)
            ->whereIn('estado', [DomiciliacionTenant::ACTIVA, DomiciliacionTenant::PENDIENTE])
            ->whereIn('acuerdo_id', AcuerdoTenant::query()->where('persona_id', $tutela->participante_id)->select('id'))
            ->get();

        foreach ($domiciliaciones as $domiciliacion) {
            $this->cerrar($domiciliacion);
        }
    }

    /**
     * El negocio (o la plataforma) apagó familias: ningún responsable sigue pagando por
     * otra persona. Sus pagos automáticos se quitan ya, también en la pasarela, sin
     * esperar a la renovación (ADR 0134).
     */
    public function alApagarFamilias(): int
    {
        $ajenas = DomiciliacionTenant::query()
            ->whereIn('estado', [DomiciliacionTenant::ACTIVA, DomiciliacionTenant::PENDIENTE])
            ->whereHas('acuerdo', fn ($q) => $q->whereColumn('acuerdos.persona_id', '!=', 'domiciliaciones.persona_id'))
            ->get();

        foreach ($ajenas as $domiciliacion) {
            $this->cerrar($domiciliacion);
        }

        return $ajenas->count();
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
        $this->recordarSesion($persona, $proveedor, $checkout);

        return ['url' => $checkout['url']];
    }

    /**
     * La sesión queda pendiente para conciliarla si su aviso no llega (ADR 0076).
     *
     * @param  array{tipo: string, url: string, referencia?: string}  $checkout
     */
    private function recordarSesion(PersonaTenant $persona, string $proveedor, array $checkout): void
    {
        $referencia = (string) ($checkout['referencia'] ?? '');
        if ($referencia === '') {
            return;
        }
        SesionTarjetaTenant::query()->firstOrCreate(
            ['referencia' => $referencia],
            ['persona_id' => $persona->getKey(), 'proveedor' => $proveedor, 'estado' => SesionTarjetaTenant::PENDIENTE],
        );
    }

    /**
     * La pasarela confirmó una tarjeta autorizada: pasa a ser la de todos los pagos
     * automáticos de la persona y se activan las membresías indicadas que se renuevan:
     * suyas o de alguien a su cargo con la autorización vigente (ADR 0129).
     * Idempotente. La tarjeta anterior se desliga.
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

            $titulares = [(int) $persona->getKey(), ...$this->familias->participantesAutorizados($persona)->modelKeys()];
            $acuerdos = AcuerdoTenant::query()
                ->whereIn('id', $acuerdoIds)
                ->whereIn('persona_id', $titulares)
                ->get();
            foreach ($acuerdos as $acuerdo) {
                if (self::renovable($acuerdo)) {
                    $this->domiciliar($acuerdo, $proveedor, $tarjeta, $persona);
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
     * automático de la membresía (con la tarjeta, si la pasarela la informa). Las
     * anteriores de la membresía (otra tarjeta, otro pagador, otra pasarela u otro
     * precio) se cancelan también en su pasarela (ADR 0134).
     */
    public function activarSuscripcion(DomiciliacionTenant $domiciliacion, ?TarjetaGuardada $tarjeta): void
    {
        $domiciliacion->update([
            ...($tarjeta instanceof TarjetaGuardada && (string) $domiciliacion->metodo_externo === '' ? $tarjeta->atributos() : []),
            'estado' => DomiciliacionTenant::ACTIVA,
            'activada_en' => Carbon::now(),
            'ultimo_error' => null,
            'ultimo_error_en' => null,
        ]);

        $anteriores = DomiciliacionTenant::query()
            ->where('acuerdo_id', $domiciliacion->acuerdo_id)
            ->whereKeyNot($domiciliacion->getKey())
            ->whereIn('estado', [DomiciliacionTenant::ACTIVA, DomiciliacionTenant::PENDIENTE])
            ->get();
        foreach ($anteriores as $anterior) {
            $this->cerrar($anterior);
        }
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

    /**
     * Quita un pago automático. Una suscripción queda `cancelando` hasta que su
     * pasarela confirma (ADR 0134); una tarjeta domiciliada se cancela aquí (el sistema
     * es quien cobra) y se desliga si ya no paga nada.
     */
    private function cerrar(DomiciliacionTenant $domiciliacion): void
    {
        if ($this->registro->resolver($domiciliacion->proveedor) instanceof PasarelaConSuscripcion) {
            $this->cancelaciones->solicitar($domiciliacion);

            return;
        }

        $domiciliacion->update(['estado' => DomiciliacionTenant::CANCELADA, 'cancelada_en' => Carbon::now()]);
        $this->olvidar((int) $domiciliacion->persona_id, $domiciliacion->proveedor, (string) $domiciliacion->metodo_externo);
    }

    /**
     * Suscribe la membresía en la pasarela: una domiciliación pendiente que se activa
     * cuando la pasarela confirma (o al momento, si ya quedó).
     *
     * @param  array<string, string>  $datos
     * @return array{estado: string, url?: string, formulario?: array<string, mixed>}
     */
    private function suscribir(AcuerdoTenant $acuerdo, string $proveedor, PasarelaConSuscripcion $pasarela, ?string $retorno, array $datos, PersonaTenant $pagador): array
    {
        $acuerdo->loadMissing('producto');
        $clave = [
            'acuerdo_id' => $acuerdo->getKey(),
            'proveedor' => $proveedor,
            'estado' => DomiciliacionTenant::PENDIENTE,
            'persona_id' => $pagador->getKey(),
        ];
        // Ya había creado una en la pasarela que aún no autoriza (p. ej. cerró la página
        // y lo intenta de nuevo): esa se cancela allá, por si la autoriza después, y se
        // crea otra (ADR 0134). Sin referencia allá (OpenPay aún sin tarjeta), se usa.
        $previa = DomiciliacionTenant::query()->where($clave)->latest('id')->first();
        if ($previa instanceof DomiciliacionTenant && (string) $previa->suscripcion_externa !== '') {
            $this->cerrar($previa);
            $previa = null;
        }
        $domiciliacion = $previa ?? DomiciliacionTenant::query()->create($clave);
        // El precio que la pasarela cobrará cada mes: el que el cliente autoriza.
        $domiciliacion->update([
            'monto_minor' => $acuerdo->producto?->precio_minor,
            'moneda' => $acuerdo->producto?->moneda,
        ]);

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
     * Domicilia la membresía con la tarjeta de quien paga (la deja como única activa).
     * Si la pagaba otra persona, su tarjeta se desliga cuando ya no paga nada más.
     */
    private function domiciliar(AcuerdoTenant $acuerdo, string $proveedor, TarjetaGuardada $tarjeta, PersonaTenant $pagador): void
    {
        // La de otra pasarela (p. ej. una suscripción de Mercado Pago) se cancela allá
        // también: si no, seguiría cobrando (ADR 0134).
        $deOtraPasarela = DomiciliacionTenant::query()
            ->where('acuerdo_id', $acuerdo->getKey())
            ->whereIn('estado', [DomiciliacionTenant::ACTIVA, DomiciliacionTenant::PENDIENTE])
            ->where('proveedor', '!=', $proveedor)
            ->get();
        foreach ($deOtraPasarela as $otra) {
            $this->cerrar($otra);
        }
        $acuerdo->loadMissing('producto');

        $anterior = DomiciliacionTenant::query()
            ->where('acuerdo_id', $acuerdo->getKey())
            ->where('proveedor', $proveedor)
            ->where('estado', DomiciliacionTenant::ACTIVA)
            ->where('persona_id', '!=', $pagador->getKey())
            ->first();

        DomiciliacionTenant::query()->updateOrCreate(
            ['acuerdo_id' => $acuerdo->getKey(), 'proveedor' => $proveedor, 'estado' => DomiciliacionTenant::ACTIVA],
            [
                ...$tarjeta->atributos(),
                'persona_id' => $pagador->getKey(),
                'ultimo_error' => null,
                'ultimo_error_en' => null,
                'activada_en' => Carbon::now(),
                // El precio que autoriza al domiciliarla (su precio contratado).
                'monto_minor' => $acuerdo->producto?->precio_minor,
                'moneda' => $acuerdo->producto?->moneda,
            ],
        );

        if ($anterior instanceof DomiciliacionTenant) {
            $this->olvidar((int) $anterior->persona_id, $proveedor, (string) $anterior->metodo_externo);
        }
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
            ReporteDeErrores::reportarAtrapado($e);
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
