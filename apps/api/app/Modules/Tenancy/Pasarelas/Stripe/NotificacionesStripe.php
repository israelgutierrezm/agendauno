<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas\Stripe;

use App\Modules\Tenancy\Application\ConfirmarPagoTenant;
use App\Modules\Tenancy\Application\ContracargosTenant;
use App\Modules\Tenancy\Application\ReembolsarPagoTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use Carbon\CarbonImmutable;

/**
 * Los avisos de Stripe de un negocio (webhook por negocio), ya con la firma comprobada:
 * se procesan aparte de la petición (ADR 0173). Confirma el pago pendiente que toca
 * (fulfillment), registra la tarjeta del pago automático, rechaza lo que ya no se puede
 * pagar, concilia devoluciones y atiende contracargos. Idempotente: Stripe reintenta y
 * puede repetir avisos.
 */
class NotificacionesStripe
{
    public function __construct(
        private readonly ConfirmarPagoTenant $confirmar,
        private readonly RegistroDePasarelasTenant $registro,
        private readonly ReembolsarPagoTenant $reembolsos,
        private readonly TarjetasStripe $tarjetas,
        private readonly ContracargosTenant $contracargos,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  el aviso tal cual llegó (`type`, `data.object`)
     */
    public function procesar(array $payload): void
    {
        $tipo = isset($payload['type']) ? (string) $payload['type'] : '';
        $objeto = is_array($payload['data'] ?? null) ? ($payload['data']['object'] ?? []) : [];
        $referencia = is_array($objeto) && isset($objeto['id']) ? (string) $objeto['id'] : '';

        // Checkout: la sesión pagada (tarjeta al momento; OXXO cuando se paga en tienda).
        // PaymentIntent: cobros creados antes de usar Checkout.
        $sesionPagada = ($tipo === 'checkout.session.completed' && is_array($objeto) && ($objeto['payment_status'] ?? '') === 'paid')
            || $tipo === 'checkout.session.async_payment_succeeded';

        // Un cargo automático trae su orden y su domiciliación: si su pago no quedó
        // registrado (la pasarela no respondió a tiempo), se registra con ellas.
        $metadatos = is_array($objeto) && is_array($objeto['metadata'] ?? null) ? $objeto['metadata'] : [];
        if ($tipo === 'payment_intent.succeeded' && $referencia !== '' && isset($metadatos['orden'], $metadatos['domiciliacion'])) {
            $this->confirmar->porCargoDomiciliado(
                $referencia,
                (string) $metadatos['orden'],
                (string) $metadatos['domiciliacion'],
                (int) ($objeto['amount_received'] ?? $objeto['amount'] ?? 0),
                (string) ($objeto['currency'] ?? ''),
            );
        } elseif (($sesionPagada || $tipo === 'payment_intent.succeeded') && $referencia !== '') {
            $this->confirmar->porReferencia($referencia);
        }

        // Tarjeta autorizada para pagos automáticos: desde la cuenta del alumno (modo
        // setup) o al pagar una compra con domiciliación (tras concederla, arriba).
        if ($tipo === 'checkout.session.completed' && is_array($objeto)
            && (($objeto['mode'] ?? '') === 'setup' || $sesionPagada)) {
            $this->tarjetas->sesionCompletada($objeto);
        }

        // Un cargo automático que el banco procesaba terminó rechazado.
        if ($tipo === 'payment_intent.payment_failed' && $referencia !== '') {
            $this->confirmar->rechazarPorReferencia($referencia);
        }

        // Devoluciones que quedaron pendientes: Stripe avisa cómo terminaron.
        if (in_array($tipo, ['refund.updated', 'refund.failed', 'charge.refund.updated'], true) && is_array($objeto)) {
            $estado = (string) ($objeto['status'] ?? '');
            if (in_array($estado, ['succeeded', 'failed', 'canceled'], true)) {
                $this->reembolsos->conciliar($referencia, $estado === 'succeeded', isset($metadatos['reembolso']) ? (string) $metadatos['reembolso'] : null);
            }
        }

        // Contracargo (ADR 0168): el banco del cliente disputa el cobro.
        if (in_array($tipo, ['charge.dispute.created', 'charge.dispute.closed'], true) && is_array($objeto)) {
            $this->disputa($tipo, $objeto);
        }

        // El intento ya no se puede pagar: la sesión venció o el pago en tienda (OXXO)
        // no se completó. Queda cerrado y se puede reintentar.
        if (in_array($tipo, ['checkout.session.expired', 'checkout.session.async_payment_failed'], true) && $referencia !== '') {
            $this->confirmar->rechazarPorReferencia($referencia);
        }
    }

    /**
     * Una disputa: se ubica su pago por el PaymentIntent (o por la sesión de Checkout que
     * lo generó) y se abre o se cierra el contracargo.
     *
     * @param  array<string, mixed>  $disputa
     */
    private function disputa(string $tipo, array $disputa): void
    {
        $intent = $disputa['payment_intent'] ?? null;
        $intent = is_array($intent) ? ($intent['id'] ?? null) : $intent;
        $referencia = (string) ($disputa['id'] ?? '');
        if (! is_string($intent) || $intent === '' || $referencia === '') {
            return;
        }
        $pago = PagoTenant::query()->where('proveedor', 'stripe')->where('referencia_externa', $intent)->first();
        if (! $pago instanceof PagoTenant) {
            $llave = $this->registro->llaves('stripe')['secret_key'] ?? '';
            $sesion = $llave !== '' ? (new ClienteStripe($llave))->sesionDeIntent($intent) : null;
            $pago = $sesion !== null ? PagoTenant::query()->where('proveedor', 'stripe')->where('referencia_externa', $sesion)->first() : null;
        }
        if (! $pago instanceof PagoTenant) {
            return;
        }

        $monto = (int) ($disputa['amount'] ?? 0);
        if ($tipo === 'charge.dispute.closed') {
            $estado = (string) ($disputa['status'] ?? '');
            if (in_array($estado, ['won', 'lost'], true)) {
                $this->contracargos->cerrado($pago, $referencia, $estado === 'won', $monto);
            }

            return;
        }
        $vence = $disputa['evidence_details']['due_by'] ?? null;
        $this->contracargos->abierto(
            $pago,
            $referencia,
            $monto,
            isset($disputa['reason']) ? (string) $disputa['reason'] : null,
            is_numeric($vence) ? CarbonImmutable::createFromTimestamp((int) $vence) : null,
        );
    }
}
