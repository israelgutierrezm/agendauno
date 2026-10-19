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
use App\Modules\Tenancy\Pasarelas\MontoDecimal;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;

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
            'payment' => $this->pago($api->pago($id)),
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
     * Un cobro de Checkout Pro cambió: se aplica a nuestro pago (su
     * `external_reference`).
     *
     * @param  array<string, mixed>  $cobro
     */
    private function pago(array $cobro): void
    {
        $pago = PagoTenant::query()
            ->where('ulid', (string) ($cobro['external_reference'] ?? ''))
            ->where('proveedor', 'mercadopago')
            ->first();
        if (! $pago instanceof PagoTenant) {
            return;
        }

        $cobroId = (string) ($cobro['id'] ?? '');
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
            'approved' => $this->confirmar->aprobar($pago, $cobroId),
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
                $this->pago($api->pago((string) $cobroId));
            }
        }
    }

    private function referir(PagoTenant $pago, string $cobroId): void
    {
        if ($pago->estado === EstadoPago::Pendiente && $cobroId !== '') {
            $pago->update(['referencia_externa' => $cobroId]);
        }
    }
}
