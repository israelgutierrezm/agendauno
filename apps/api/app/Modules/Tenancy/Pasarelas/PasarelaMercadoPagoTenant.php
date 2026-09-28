<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\DomiciliacionNoPermitida;
use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pasarelas\MercadoPago\ClienteMercadoPago;
use Illuminate\Support\Carbon;

/**
 * Cobro en línea con Mercado Pago (Checkout Pro) usando el access token del
 * negocio: abre una preferencia (página de pago de Mercado Pago: tarjeta, OXXO,
 * transferencia) y devuelve `pendiente` con la URL; la notificación del webhook
 * hace releer el pago en la API y lo confirma.
 *
 * La referencia del pago es la preferencia mientras no hay cobro; en cuanto Mercado
 * Pago crea uno (p. ej. el ticket de OXXO) pasa a ser el id de ese pago.
 *
 * Pago automático: suscripción sin plan (`preapproval`) por membresía, que el
 * cliente autoriza en Mercado Pago; Mercado Pago cobra cada mes (reintenta hasta 4
 * veces en 10 días) y aquí se concilian sus cobros.
 */
class PasarelaMercadoPagoTenant implements PasarelaCancelable, PasarelaConsultable, PasarelaConSuscripcion, PasarelaReembolsable, PasarelaTenant
{
    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        // Días para pagar en tienda (OXXO): los fija el negocio (ADR 0047).
        private readonly ParametrosTenant $parametros,
    ) {}

    public function nombre(): string
    {
        return 'mercadopago';
    }

    public function cobrar(PagoTenant $pago, array $llaves): ResultadoPago
    {
        $orden = $pago->orden()->with(['lineas.producto', 'persona'])->first();
        $retorno = RetornoPago::urls($pago->retorno);

        $preferencia = self::api($llaves)->crearPreferencia(
            $pago->monto_minor,
            $pago->moneda,
            ConceptoDeCobro::de($orden),
            (string) $pago->ulid,
            $this->urlNotificaciones(),
            ['success' => $retorno['exito'], 'pending' => $retorno['exito'], 'failure' => $retorno['cancelado']],
            $orden?->persona?->email,
            Carbon::now()->addDays($this->parametros->entero('cobranza.dias_pagar_en_tienda')),
        );

        return ResultadoPago::pendiente($preferencia['id'], [
            'tipo' => 'redirect',
            'url' => $preferencia['url'],
        ]);
    }

    /**
     * Cierra el intento para abrir otro: cancela lo que Mercado Pago tenga sin cobrar
     * (p. ej. un ticket de OXXO) y vence la preferencia. Si ya se pagó, o el cobro se
     * está procesando, no se puede: devuelve false.
     */
    public function cancelar(PagoTenant $pago, array $llaves): bool
    {
        if (($llaves['access_token'] ?? '') === '') {
            return true;
        }
        $api = self::api($llaves);

        foreach ($api->pagosDeReferencia((string) $pago->ulid) as $cobro) {
            $estado = (string) ($cobro['status'] ?? '');
            if (in_array($estado, ['approved', 'authorized'], true)) {
                return false;
            }
            if (in_array($estado, ['pending', 'in_process', 'in_mediation'], true)
                && ! $api->cancelarPago((string) ($cobro['id'] ?? ''))) {
                return false;
            }
        }

        $referencia = (string) $pago->referencia_externa;
        if (str_contains($referencia, '-')) {
            $api->expirarPreferencia($referencia);
        }

        return true;
    }

    /**
     * Cómo va el intento: los cobros de Mercado Pago para nuestro pago (su
     * `external_reference`). Un cobro aprobado se aprueba con su id, como lo haría el
     * aviso; uno en espera (p. ej. el ticket de OXXO) deja el intento pendiente. Sin
     * cobro vivo, se puede pagar mientras la preferencia siga vigente y no se haya
     * cerrado de nuestro lado (al cerrarla se vence).
     */
    public function consultar(PagoTenant $pago, array $llaves): ResultadoPago
    {
        $cobros = self::api($llaves)->pagosDeReferencia((string) $pago->ulid);

        foreach ($cobros as $cobro) {
            if (($cobro['status'] ?? '') === 'approved') {
                return ResultadoPago::aprobado((string) ($cobro['id'] ?? ''));
            }
        }
        foreach ($cobros as $cobro) {
            if (in_array($cobro['status'] ?? '', ['pending', 'in_process', 'authorized'], true)) {
                return ResultadoPago::pendiente((string) ($cobro['id'] ?? ''));
            }
        }

        $vigente = $pago->estado === EstadoPago::Pendiente
            && $pago->created_at?->copy()->addDays($this->parametros->entero('cobranza.dias_pagar_en_tienda'))->isFuture() === true;

        return $vigente
            ? ResultadoPago::pendiente((string) $pago->referencia_externa)
            : ResultadoPago::rechazado('El pago en Mercado Pago ya no se puede completar.');
    }

    /**
     * Devuelve dinero del cobro aprobado. Mercado Pago devuelve a la tarjeta; lo
     * pagado en efectivo va al saldo de Mercado Pago del cliente.
     */
    public function reembolsar(PagoTenant $pago, int $montoMinor, array $llaves, string $idempotencia): ResultadoPago
    {
        $api = self::api($llaves);
        $cobro = self::cobroAprobado($api, $pago);
        if ($cobro === null) {
            throw new PasarelaNoDisponible('No se encontró el cobro en Mercado Pago.');
        }

        $reembolso = $api->reembolsar($cobro, $montoMinor, $idempotencia);

        return match ($reembolso['status']) {
            'approved' => ResultadoPago::aprobado($reembolso['id']),
            'in_process', 'authorized', 'pending' => ResultadoPago::pendiente($reembolso['id']),
            default => ResultadoPago::rechazado('Mercado Pago rechazó la devolución ('.$reembolso['status'].').'),
        };
    }

    public function suscribir(DomiciliacionTenant $domiciliacion, AcuerdoTenant $acuerdo, ?string $retorno, array $datos, array $llaves): array
    {
        $acuerdo->loadMissing(['producto', 'persona']);
        $email = (string) $acuerdo->persona?->email;
        if ($email === '') {
            throw new DomiciliacionNoPermitida('Para el pago automático con Mercado Pago necesitas un correo registrado.');
        }
        $producto = $acuerdo->producto ?? throw new DomiciliacionNoPermitida('La membresía no tiene producto.');

        // El primer cobro, en la próxima renovación (Mercado Pago solo respeta el
        // inicio si también hay fin).
        $inicio = Carbon::parse($acuerdo->proxima_cobro_en ?? Carbon::now());
        $inicio = $inicio->isFuture() ? $inicio->startOfDay()->addHours(9) : Carbon::now()->addHour();

        $suscripcion = self::api($llaves)->crearSuscripcion(
            (string) $producto->nombre,
            (string) $domiciliacion->ulid,
            $email,
            (int) $producto->precio_minor,
            (string) $producto->moneda,
            RetornoPago::urls($retorno, 'tarjeta')['exito'],
            $inicio,
            $inicio->copy()->addYears(5),
        );
        $domiciliacion->update(['suscripcion_externa' => $suscripcion['id']]);

        return ['estado' => 'redirect', 'url' => $suscripcion['url']];
    }

    public function consultarSuscripcion(DomiciliacionTenant $domiciliacion, array $llaves): array
    {
        $suscripcion = self::api($llaves)->suscripcion((string) $domiciliacion->suscripcion_externa);
        $metodo = (string) ($suscripcion['payment_method_id'] ?? '');

        return [
            'estado' => match ((string) ($suscripcion['status'] ?? '')) {
                'authorized' => 'activa',
                'canceled', 'cancelled' => 'cancelada',
                'paused' => 'pausada',
                default => 'pendiente',
            },
            'tarjeta' => $metodo !== '' ? new TarjetaGuardada((string) ($suscripcion['card_id'] ?? $metodo), $metodo) : null,
        ];
    }

    public function cobrosDeSuscripcion(DomiciliacionTenant $domiciliacion, array $llaves): array
    {
        $cobros = [];
        foreach (self::api($llaves)->cuotasDeSuscripcion((string) $domiciliacion->suscripcion_externa) as $cuota) {
            $pago = is_array($cuota['payment'] ?? null) ? $cuota['payment'] : [];
            $estado = (string) ($pago['status'] ?? '');
            $monto = MontoDecimal::aCentavos((float) ($cuota['transaction_amount'] ?? 0));

            if ($estado === 'approved') {
                $cobros[] = new CobroDeSuscripcion((string) ($pago['id'] ?? $cuota['id'] ?? ''), true, $monto);
            } elseif ($estado === 'rejected') {
                // Cada reintento rechazado cuenta una vez (Mercado Pago reintenta la cuota).
                $cobros[] = new CobroDeSuscripcion(
                    'cuota_'.($cuota['id'] ?? '').'_'.($cuota['retry_attempt'] ?? 0),
                    false,
                    $monto,
                    self::motivoRechazo((string) ($pago['status_detail'] ?? '')),
                );
            }
        }

        return $cobros;
    }

    public function cancelarSuscripcion(DomiciliacionTenant $domiciliacion, array $llaves): void
    {
        if ((string) $domiciliacion->suscripcion_externa !== '') {
            self::api($llaves)->cancelarSuscripcion((string) $domiciliacion->suscripcion_externa);
        }
    }

    private static function motivoRechazo(string $detalle): string
    {
        return match ($detalle) {
            'cc_rejected_insufficient_amount' => 'La tarjeta no tiene fondos suficientes.',
            'cc_rejected_card_disabled', 'cc_rejected_blacklist' => 'La tarjeta ya no es válida.',
            'cc_rejected_call_for_authorize' => 'Tu banco pide que autorices el cargo.',
            'cc_rejected_bad_filled_date' => 'La tarjeta venció.',
            default => 'El banco rechazó el cargo.',
        };
    }

    /**
     * El id del cobro aprobado del pago: su referencia si ya es un cobro, o el que
     * Mercado Pago tenga aprobado para él.
     */
    private static function cobroAprobado(ClienteMercadoPago $api, PagoTenant $pago): ?string
    {
        $referencia = (string) $pago->referencia_externa;
        if ($referencia !== '' && ctype_digit($referencia)) {
            return $referencia;
        }

        foreach ($api->pagosDeReferencia((string) $pago->ulid) as $cobro) {
            if (($cobro['status'] ?? '') === 'approved') {
                return (string) ($cobro['id'] ?? '');
            }
        }

        return null;
    }

    private function urlNotificaciones(): string
    {
        return route('api.v1.webhooks.tenant', [
            'estudio' => (string) $this->gestor->actual()?->slug,
            'proveedor' => 'mercadopago',
        ]);
    }

    /**
     * @param  array<string, string>  $llaves
     */
    private static function api(array $llaves): ClienteMercadoPago
    {
        $token = $llaves['access_token'] ?? '';
        if ($token === '') {
            throw new PasarelaNoDisponible('Mercado Pago no tiene llaves configuradas.');
        }

        return new ClienteMercadoPago($token);
    }
}
