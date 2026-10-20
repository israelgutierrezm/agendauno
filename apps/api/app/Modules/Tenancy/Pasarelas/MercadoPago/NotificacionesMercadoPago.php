<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas\MercadoPago;

use App\Modules\Tenancy\Application\ConciliarSuscripcionTenant;
use App\Modules\Tenancy\Application\ConfirmarPagoTenant;
use App\Modules\Tenancy\Application\ContracargosTenant;
use App\Modules\Tenancy\Application\ReembolsarPagoTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Modules\Tenancy\Pasarelas\MontoDecimal;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use App\Support\ReporteDeErrores;
use Throwable;

/**
 * Notificaciones de Mercado Pago (webhook por negocio). La notificación solo dice
 * qué cambió; el estado real se lee siempre de la API con el access token del
 * negocio, así una notificación falsa no puede aprobar nada. Idempotente: Mercado
 * Pago reintenta y puede repetir avisos.
 */
class NotificacionesMercadoPago
{
    public function __construct(
        private readonly RegistroDePasarelasTenant $registro,
        private readonly ConfirmarPagoTenant $confirmar,
        private readonly ReembolsarPagoTenant $reembolsos,
        private readonly ConciliarSuscripcionTenant $suscripciones,
        private readonly ContracargosTenant $contracargos,
    ) {}

    public function procesar(string $tipo, string $id): void
    {
        $token = $this->registro->llaves('mercadopago')['access_token'] ?? '';
        if ($token === '' || $id === '') {
            return;
        }
        $api = new ClienteMercadoPago($token);

        match ($tipo) {
            'payment' => $this->pago($api, $api->pago($id)),
            // La suscripción (pago automático) se autorizó, pausó o canceló.
            'subscription_preapproval', 'preapproval' => $this->suscripcion($id),
            // Mercado Pago cobró (o intentó cobrar) una cuota de la suscripción.
            'subscription_authorized_payment', 'authorized_payment' => $this->suscripcion(
                (string) ($api->cuota($id)['preapproval_id'] ?? ''),
            ),
            // Un contracargo (ADR 0168): se releen sus pagos, que dicen en qué va.
            'chargebacks', 'chargeback' => $this->contracargo($api, $id),
            default => null,
        };
    }

    private function suscripcion(string $preapproval): void
    {
        $domiciliacion = $preapproval === '' ? null : DomiciliacionTenant::query()
            ->where('proveedor', 'mercadopago')
            ->where('suscripcion_externa', $preapproval)
            ->latest('id')
            ->first();
        if ($domiciliacion instanceof DomiciliacionTenant) {
            $this->suscripciones->actualizar($domiciliacion);
        }
    }

    /**
     * Un cobro cambió: se aplica a nuestro pago ({@see self::pagoDelCobro()}).
     *
     * @param  array<string, mixed>  $cobro
     */
    private function pago(ClienteMercadoPago $api, array $cobro): void
    {
        $cobroId = (string) ($cobro['id'] ?? '');
        $pago = $this->pagoDelCobro($cobro, $cobroId);
        if (! $pago instanceof PagoTenant) {
            return;
        }
        // Contracargo (ADR 0168): en proceso (abierto), «settled» (perdido: el dinero
        // se devolvió al cliente) o «reimbursed» (ganado: el dinero volvió al negocio).
        if ((string) ($cobro['status'] ?? '') === 'charged_back' && $cobroId !== '') {
            $monto = MontoDecimal::aCentavos((float) ($cobro['transaction_amount'] ?? 0));
            match ((string) ($cobro['status_detail'] ?? '')) {
                'settled' => $this->contracargos->cerrado($pago, $cobroId, false, $monto),
                'reimbursed' => $this->contracargos->cerrado($pago, $cobroId, true, $monto),
                default => $this->contracargos->abierto($pago, $cobroId, $monto, 'Contracargo en Mercado Pago'),
            };

            return;
        }
        match ((string) ($cobro['status'] ?? '')) {
            'approved' => $this->aprobado($api, $pago, $cobro, $cobroId),
            // El cobro existe pero falta (p. ej. el ticket de OXXO): la referencia pasa a
            // ser ese cobro, para poder cancelarlo o devolverlo.
            'pending', 'in_process', 'authorized' => $this->referir($pago, $cobroId),
            // Venció sin pagarse (efectivo) o se canceló: el intento queda cerrado.
            'cancelled', 'canceled' => $this->confirmar->rechazarPorReferencia($cobroId),
            // Un rechazo de tarjeta no cierra el intento: el cliente puede probar otra
            // tarjeta en la misma página de pago.
            default => null,
        };

        // Devoluciones que quedaron en proceso: cómo terminaron.
        $devoluciones = is_array($cobro['refunds'] ?? null) ? $cobro['refunds'] : [];
        foreach ($devoluciones as $devolucion) {
            if (! is_array($devolucion)) {
                continue;
            }
            $estado = (string) ($devolucion['status'] ?? '');
            if (in_array($estado, ['approved', 'rejected', 'cancelled', 'canceled'], true)) {
                $this->reembolsos->conciliar((string) ($devolucion['id'] ?? ''), $estado === 'approved');
            }
        }
    }

    /**
     * El aviso de un contracargo: se releen sus pagos (de ahí sale en qué va).
     */
    private function contracargo(ClienteMercadoPago $api, string $id): void
    {
        $pagos = $api->contracargo($id)['payments'] ?? [];
        foreach (is_array($pagos) ? $pagos : [] as $cobroId) {
            if (is_scalar($cobroId) && (string) $cobroId !== '') {
                $this->pago($api, $api->pago((string) $cobroId));
            }
        }
    }

    /**
     * El pago al que toca el cobro: el que ya lo tiene como referencia (un cobro doble
     * ya registrado, o la cuota de un pago automático, cuya `external_reference` es la
     * domiciliación) o, si no, el intento de Checkout Pro de su `external_reference`.
     *
     * @param  array<string, mixed>  $cobro
     */
    private function pagoDelCobro(array $cobro, string $cobroId): ?PagoTenant
    {
        $porCobro = $cobroId === '' ? null : PagoTenant::query()
            ->where('proveedor', 'mercadopago')
            ->where('referencia_externa', $cobroId)
            ->first();

        return $porCobro ?? PagoTenant::query()
            ->where('ulid', (string) ($cobro['external_reference'] ?? ''))
            ->where('proveedor', 'mercadopago')
            ->first();
    }

    /**
     * Un cobro aprobado. Si el intento ya quedó pagado con OTRO cobro de la misma
     * preferencia (p. ej. pagó con tarjeta y después también el ticket de OXXO), este se
     * registra aparte: es dinero cobrado de más, por devolver. Si con este se pagó, los
     * demás cobros por pagar de la preferencia se cancelan.
     *
     * @param  array<string, mixed>  $cobro
     */
    private function aprobado(ClienteMercadoPago $api, PagoTenant $pago, array $cobro, string $cobroId): void
    {
        if ($cobroId !== '' && (string) $pago->referencia_externa !== $cobroId
            && ! in_array($pago->estado, [EstadoPago::Pendiente, EstadoPago::Rechazado], true)) {
            $monto = MontoDecimal::aCentavos((float) ($cobro['transaction_amount'] ?? 0));
            $this->confirmar->otroCobro(
                $pago,
                $cobroId,
                $monto > 0 ? $monto : (int) $pago->monto_minor,
                (string) ($cobro['currency_id'] ?? $pago->moneda),
                self::metodoDe($cobro, $pago),
            );

            return;
        }

        $this->confirmar->aprobar($pago, $cobroId);
        if ((string) ($cobro['external_reference'] ?? '') === (string) $pago->ulid) {
            $this->cancelarOtrosCobros($api, $pago->refresh(), $cobroId);
        }
    }

    /**
     * Pagado con este cobro: los demás de la misma preferencia que sigan por pagar (el
     * ticket de OXXO) se cancelan, para que no se cobre dos veces. Si no se puede, el que
     * llegue a pagarse se registra como cobro doble.
     */
    private function cancelarOtrosCobros(ClienteMercadoPago $api, PagoTenant $pago, string $cobroId): void
    {
        if ($pago->estado !== EstadoPago::Aprobado || (string) $pago->referencia_externa !== $cobroId) {
            return;
        }

        try {
            foreach ($api->pagosDeReferencia((string) $pago->ulid) as $otro) {
                $otroId = (string) ($otro['id'] ?? '');
                if ($otroId !== '' && $otroId !== $cobroId && in_array((string) ($otro['status'] ?? ''), ['pending', 'in_process'], true)) {
                    $api->cancelarPago($otroId);
                }
            }
        } catch (Throwable $e) {
            ReporteDeErrores::reportarAtrapado($e);
        }
    }

    /**
     * @param  array<string, mixed>  $cobro
     */
    private static function metodoDe(array $cobro, PagoTenant $pago): MetodoPago
    {
        return match ((string) ($cobro['payment_type_id'] ?? '')) {
            'credit_card', 'debit_card', 'prepaid_card' => MetodoPago::Tarjeta,
            'ticket', 'atm' => MetodoPago::Oxxo,
            'bank_transfer' => MetodoPago::Spei,
            default => $pago->metodo instanceof MetodoPago ? $pago->metodo : MetodoPago::Tarjeta,
        };
    }

    private function referir(PagoTenant $pago, string $cobroId): void
    {
        if ($pago->estado === EstadoPago::Pendiente && $cobroId !== '') {
            $pago->update(['referencia_externa' => $cobroId]);
        }
    }
}
