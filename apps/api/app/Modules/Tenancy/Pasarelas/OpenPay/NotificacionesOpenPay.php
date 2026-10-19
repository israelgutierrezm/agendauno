<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas\OpenPay;

use App\Modules\Tenancy\Application\ConciliarSuscripcionTenant;
use App\Modules\Tenancy\Application\ConfirmarPagoTenant;
use App\Modules\Tenancy\Application\ContracargosTenant;
use App\Modules\Tenancy\Application\ReembolsarPagoTenant;
use App\Modules\Tenancy\Models\ConfiguracionPasarelaTenant;
use App\Modules\Tenancy\Models\DomiciliacionTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Pasarelas\MontoDecimal;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;

/**
 * Notificaciones de OpenPay (webhook por negocio, protegido con usuario y
 * contraseña). El aviso solo dice qué cambió: el cargo se relee en la API con la
 * llave privada del negocio antes de confirmar nada. Idempotente: OpenPay reintenta
 * hasta recibir 200 y puede repetir avisos.
 *
 * Al registrar el webhook, OpenPay manda un código de verificación: se guarda para
 * que el dueño lo vea en Pasarelas y lo capture en el tablero de OpenPay.
 */
class NotificacionesOpenPay
{
    public function __construct(
        private readonly RegistroDePasarelasTenant $registro,
        private readonly ConfirmarPagoTenant $confirmar,
        private readonly ReembolsarPagoTenant $reembolsos,
        private readonly ConciliarSuscripcionTenant $suscripciones,
        private readonly ContracargosTenant $contracargos,
    ) {}

    /**
     * @param  array<string, mixed>  $evento
     */
    public function procesar(array $evento): void
    {
        $tipo = (string) ($evento['type'] ?? '');

        if ($tipo === 'verification') {
            ConfiguracionPasarelaTenant::query()
                ->where('proveedor', 'openpay')
                ->update(['codigo_verificacion' => substr((string) ($evento['verification_code'] ?? ''), 0, 40)]);

            return;
        }

        $transaccion = is_array($evento['transaction'] ?? null) ? $evento['transaction'] : [];
        $id = (string) ($transaccion['id'] ?? '');
        $llaves = $this->registro->llaves('openpay');
        if ($id === '' || ($llaves['merchant_id'] ?? '') === '' || ($llaves['private_key'] ?? '') === '') {
            return;
        }

        if ($tipo === 'charge.refunded') {
            $devolucion = is_array($transaccion['refund'] ?? null) ? $transaccion['refund'] : [];
            $this->reembolsos->conciliar((string) ($devolucion['id'] ?? ''), true);

            return;
        }

        // Cargo de una suscripción (pago automático): se concilian los del cliente de
        // OpenPay de esa membresía.
        $suscripcion = $this->domiciliacionDelCliente((string) ($transaccion['customer_id'] ?? ''));
        if ($tipo === 'subscription.charge.failed' || ($suscripcion instanceof DomiciliacionTenant && ($transaccion['order_id'] ?? null) === null)) {
            if ($suscripcion instanceof DomiciliacionTenant) {
                $this->suscripciones->actualizar($suscripcion);
            }

            return;
        }

        // Contracargo (ADR 0168): se relee el cargo en OpenPay para ubicar su pago.
        if (in_array($tipo, ['chargeback.created', 'chargeback.accepted', 'chargeback.rejected'], true)) {
            $modo = ConfiguracionPasarelaTenant::query()->where('proveedor', 'openpay')->value('modo');
            $cargo = (new ClienteOpenPay($llaves['merchant_id'], $llaves['private_key'], $modo !== 'live'))->cargo($id);
            $pago = PagoTenant::query()->where('ulid', (string) ($cargo['order_id'] ?? ''))->where('proveedor', 'openpay')->first();
            if ($pago instanceof PagoTenant) {
                $monto = MontoDecimal::aCentavos((float) ($cargo['amount'] ?? 0));
                match ($tipo) {
                    // Aceptado: el negocio lo perdió (se devolvió al cliente).
                    'chargeback.accepted' => $this->contracargos->cerrado($pago, $id, false, $monto),
                    // Rechazado: el negocio lo ganó.
                    'chargeback.rejected' => $this->contracargos->cerrado($pago, $id, true, $monto),
                    default => $this->contracargos->abierto($pago, $id, $monto, 'Contracargo en OpenPay'),
                };
            }

            return;
        }

        if (! in_array($tipo, ['charge.succeeded', 'charge.failed', 'charge.cancelled'], true)) {
            return;
        }

        $modo = ConfiguracionPasarelaTenant::query()->where('proveedor', 'openpay')->value('modo');
        $cargo = (new ClienteOpenPay($llaves['merchant_id'], $llaves['private_key'], $modo !== 'live'))->cargo($id);

        $pago = PagoTenant::query()
            ->where('ulid', (string) ($cargo['order_id'] ?? ''))
            ->where('proveedor', 'openpay')
            ->first();
        if (! $pago instanceof PagoTenant) {
            return;
        }

        match ((string) ($cargo['status'] ?? '')) {
            'completed' => $this->confirmar->aprobar($pago, $id),
            'failed', 'cancelled' => $this->confirmar->rechazarPorReferencia($id),
            default => null,
        };
    }

    private function domiciliacionDelCliente(string $cliente): ?DomiciliacionTenant
    {
        return $cliente === '' ? null : DomiciliacionTenant::query()
            ->where('proveedor', 'openpay')
            ->where('cliente_externo', $cliente)
            ->latest('id')
            ->first();
    }
}
