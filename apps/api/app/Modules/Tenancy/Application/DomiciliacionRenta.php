<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Exceptions\CobroNoConcluyente;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasPlataforma;
use App\Modules\Tenancy\Pasarelas\RetornoPago;
use App\Modules\Tenancy\Pasarelas\Stripe\ClienteStripe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Domiciliación de la renta (ADR 0107): el dueño deja su tarjeta en Stripe (cuenta de
 * la plataforma, sesión de Checkout en modo `setup`) y cada cargo de la renta se le
 * cobra solo (`off_session`).
 *
 * - Si el banco rechaza, se reintenta a los 3 y a los 7 días de emitido el cargo.
 * - Si la tarjeta pide autenticación, ya no se reintenta: el dueño paga en «Mi
 *   suscripción» como siempre.
 * - Un cargo con un pago en línea en curso (Checkout) no se cobra a la tarjeta.
 * - Sigue aplicando la suspensión por renta vencida (ADR 0073).
 *
 * De la tarjeta solo se guarda su marca, sus últimos 4 dígitos y su vencimiento.
 */
class DomiciliacionRenta
{
    /** Reintentos tras un rechazo: días después de emitido el cargo. */
    public const REINTENTOS_DIAS = [3, 7];

    /** El tipo de la sesión de Stripe con que se guarda la tarjeta. */
    private const TIPO_SESION = 'domiciliacion_renta';

    public function __construct(
        private readonly RegistroDePasarelasPlataforma $registro,
        private readonly SuspensionPorRenta $suspension,
    ) {}

    /**
     * Abre la página de Stripe para guardar la tarjeta. Al volver, la web confirma
     * con la sesión (`?tarjeta=exito&sesion=…`); el aviso de Stripe también la guarda.
     *
     * @return array{url: string}
     */
    public function iniciar(Estudio $estudio, string $retorno = '/renta'): array
    {
        $stripe = $this->stripe();
        if ((string) $estudio->stripe_cliente_id === '') {
            $cliente = $stripe->crearCliente((string) $estudio->nombre, $estudio->contacto_email, 'agendauno-estudio-'.$estudio->ulid, [
                'estudio' => (string) $estudio->ulid,
            ]);
            $estudio->update(['stripe_cliente_id' => $cliente]);
        }

        $urls = RetornoPago::urls($retorno, 'tarjeta');
        $sesion = $stripe->crearSesionGuardado(
            (string) $estudio->stripe_cliente_id,
            $urls['exito'].'&sesion={CHECKOUT_SESSION_ID}',
            $urls['cancelado'],
            ['estudio' => (string) $estudio->ulid, 'tipo' => self::TIPO_SESION],
        );

        return ['url' => $sesion['url']];
    }

    /**
     * Guarda la tarjeta autorizada en una sesión de Stripe (al volver o por su aviso).
     * Solo si la sesión es del negocio esperado (si se indica). Idempotente.
     */
    public function guardarDeSesion(string $sesionId, ?Estudio $esperado = null): ?Estudio
    {
        $tarjeta = $this->stripe()->tarjetaDeSesion($sesionId);
        if ($tarjeta === null || ($tarjeta['metadata']['tipo'] ?? null) !== self::TIPO_SESION) {
            return null;
        }
        $estudio = Estudio::query()->where('ulid', (string) ($tarjeta['metadata']['estudio'] ?? ''))->first();
        if (! $estudio instanceof Estudio || ($esperado !== null && ! $esperado->is($estudio))
            || (string) $estudio->stripe_cliente_id !== $tarjeta['cliente']) {
            return null;
        }

        $anterior = (string) $estudio->domiciliacion_metodo;
        $vence = $tarjeta['expira_mes'] !== null && $tarjeta['expira_anio'] !== null
            ? str_pad((string) $tarjeta['expira_mes'], 2, '0', STR_PAD_LEFT).'/'.$tarjeta['expira_anio']
            : null;
        $estudio->update([
            'domiciliacion_metodo' => $tarjeta['metodo'],
            'tarjeta_marca' => $tarjeta['marca'],
            'tarjeta_ultimos4' => $tarjeta['ultimos4'],
            'tarjeta_vence' => $vence,
            'domiciliada_en' => $anterior === $tarjeta['metodo'] ? $estudio->domiciliada_en : Carbon::now(),
        ]);
        // La tarjeta anterior ya no se usa.
        if ($anterior !== '' && $anterior !== $tarjeta['metodo']) {
            $this->desligar($anterior);
        }

        return $estudio;
    }

    /** Quita la tarjeta: los siguientes cargos se pagan a mano. */
    public function quitar(Estudio $estudio): void
    {
        $metodo = (string) $estudio->domiciliacion_metodo;
        $estudio->update([
            'domiciliacion_metodo' => null, 'tarjeta_marca' => null, 'tarjeta_ultimos4' => null,
            'tarjeta_vence' => null, 'domiciliada_en' => null,
        ]);
        if ($metodo !== '') {
            $this->desligar($metodo);
        }
    }

    /**
     * La tarjeta domiciliada, para mostrarla.
     *
     * @return array{marca: string|null, ultimos4: string|null, vence: string|null, desde: string|null}|null
     */
    public static function tarjeta(Estudio $estudio): ?array
    {
        if ((string) $estudio->domiciliacion_metodo === '') {
            return null;
        }

        return [
            'marca' => $estudio->tarjeta_marca,
            'ultimos4' => $estudio->tarjeta_ultimos4,
            'vence' => $estudio->tarjeta_vence,
            'desde' => $estudio->domiciliada_en?->toIso8601String(),
        ];
    }

    /**
     * Cobra a la tarjeta domiciliada un cargo pendiente. Devuelve cómo quedó:
     * `pagado`, `en_proceso`, `rechazado`, `autenticacion`, `reintentar` (Stripe no
     * respondió) u `omitido` (sin tarjeta, ya pagado o con un pago en curso).
     */
    public function cobrar(CargoRenta $cargo): string
    {
        $cargo->loadMissing('estudio');
        $estudio = $cargo->estudio;
        if (! $estudio instanceof Estudio || (string) $estudio->domiciliacion_metodo === '' || (string) $estudio->stripe_cliente_id === ''
            || ! $this->registro->activa('stripe')) {
            return 'omitido';
        }

        $resultado = DB::transaction(function () use ($cargo, $estudio): string {
            $bloqueado = CargoRenta::query()->whereKey($cargo->getKey())->lockForUpdate()->firstOrFail();
            if ($bloqueado->estado !== EstadoCargoRenta::Pendiente || $bloqueado->monto_minor <= 0
                || (string) $bloqueado->referencia_pago !== '') {
                return 'omitido';
            }

            try {
                $cobro = $this->stripe()->cobrarGuardado(
                    $bloqueado->monto_minor,
                    $bloqueado->moneda,
                    (string) $estudio->stripe_cliente_id,
                    (string) $estudio->domiciliacion_metodo,
                    ReciboRentaPdf::concepto($bloqueado),
                    // La misma llave en cada reintento del mismo intento: Stripe no cobra dos veces.
                    'agendauno-renta-'.$bloqueado->ulid.'-'.$bloqueado->intentos_automaticos,
                    ['cargo_renta' => (string) $bloqueado->ulid],
                );
            } catch (CobroNoConcluyente) {
                $bloqueado->update(['proximo_intento_en' => Carbon::now()->addHour()]);

                return 'reintentar';
            }

            if ($cobro['status'] === 'succeeded') {
                $bloqueado->update([
                    'estado' => EstadoCargoRenta::Pagado->value, 'pagado_en' => Carbon::now(),
                    'metodo_pago' => 'stripe', 'referencia_pago' => $cobro['id'],
                    'error_cobro' => null, 'proximo_intento_en' => null,
                ]);

                return 'pagado';
            }
            if ($cobro['status'] === 'processing') {
                // El aviso de Stripe (o la conciliación) lo confirma.
                $bloqueado->update(['metodo_pago' => 'stripe', 'referencia_pago' => $cobro['id'], 'proximo_intento_en' => null]);

                return 'en_proceso';
            }

            $intentos = $bloqueado->intentos_automaticos + 1;
            $autenticacion = $cobro['status'] === 'requires_action' || $cobro['codigo'] === 'authentication_required';
            $dias = self::REINTENTOS_DIAS[$intentos - 1] ?? null;
            $emitido = $bloqueado->emitido_en ?? Carbon::now();
            $bloqueado->update([
                'intentos_automaticos' => $intentos,
                'error_cobro' => $autenticacion ? 'authentication_required' : (string) ($cobro['codigo'] ?? 'rechazado'),
                'proximo_intento_en' => $autenticacion || $dias === null ? null : Carbon::parse($emitido)->addDays($dias),
            ]);

            return $autenticacion ? 'autenticacion' : 'rechazado';
        });

        if ($resultado === 'pagado') {
            $this->suspension->reactivarSiPago($estudio->refresh());
        }

        return $resultado;
    }

    /**
     * Los cargos pendientes que toca cobrar a la tarjeta: recién emitidos o con un
     * reintento ya vencido.
     *
     * @return int cuántos se pagaron
     */
    public function cobrarPendientes(): int
    {
        if (! $this->registro->activa('stripe')) {
            return 0;
        }
        $ahora = Carbon::now();

        return CargoRenta::query()
            ->where('estado', EstadoCargoRenta::Pendiente->value)
            ->where('monto_minor', '>', 0)
            ->whereNull('referencia_pago')
            // Una compra de timbres se paga al comprarla, no a la tarjeta.
            ->where(fn ($q) => $q->whereNull('concepto')->orWhere('concepto', '!=', 'timbres'))
            ->where('intentos_automaticos', '<=', count(self::REINTENTOS_DIAS))
            ->where(fn ($q) => $q->whereNull('error_cobro')->orWhere('error_cobro', '!=', 'authentication_required'))
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('intentos_automaticos', 0)->whereNull('proximo_intento_en'))
                ->orWhere('proximo_intento_en', '<=', $ahora))
            ->whereHas('estudio', fn ($q) => $q->whereNotNull('domiciliacion_metodo'))
            ->orderBy('id')
            ->get()
            ->filter(function (CargoRenta $cargo): bool {
                try {
                    return $this->cobrar($cargo) === 'pagado';
                } catch (Throwable $e) {
                    Log::warning('No se pudo cobrar la renta a la tarjeta domiciliada.', ['cargo' => $cargo->ulid, 'error' => $e->getMessage()]);
                    report($e);

                    return false;
                }
            })
            ->count();
    }

    private function stripe(): ClienteStripe
    {
        $llave = (string) ($this->registro->llaves('stripe')['secret_key'] ?? '');
        if (! $this->registro->activa('stripe') || $llave === '') {
            throw new PasarelaNoDisponible('Stripe no está listo para cobrar la renta.');
        }

        return new ClienteStripe($llave);
    }

    private function desligar(string $metodo): void
    {
        try {
            $this->stripe()->desligarMetodo($metodo);
        } catch (Throwable $e) {
            Log::warning('No se pudo desligar la tarjeta en Stripe.', ['error' => $e->getMessage()]);
        }
    }
}
