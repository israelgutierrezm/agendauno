<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Pagos\EstadoPago;
use Illuminate\Support\Facades\DB;

/**
 * Confirma un pago pendiente tenant-local a partir de la referencia del intento (la
 * que envia el webhook de la pasarela) y hace el fulfillment de su orden.
 * Idempotente: un pago que no esta pendiente no se reprocesa.
 */
class ConfirmarPagoTenant
{
    public function __construct(
        private readonly FulfillmentTenant $fulfillment,
    ) {}

    public function porReferencia(string $referencia): void
    {
        if ($referencia === '') {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($referencia): void {
            $pago = PagoTenant::query()
                ->where('referencia_externa', $referencia)
                ->lockForUpdate()
                ->first();

            if (! $pago instanceof PagoTenant || $pago->estado !== EstadoPago::Pendiente) {
                return;
            }

            $pago->update(['estado' => EstadoPago::Aprobado->value]);

            $orden = $pago->orden;
            if ($orden !== null) {
                // El fulfillment asienta orden.pagada (recibo, puntos de lealtad).
                $this->fulfillment->cumplir($orden);
            }
        });
    }

    /**
     * El intento ya no se puede pagar (la sesión venció o el pago en tienda no se
     * completó): queda rechazado. La orden sigue pendiente y se puede reintentar.
     * Idempotente.
     */
    public function rechazarPorReferencia(string $referencia): void
    {
        if ($referencia === '') {
            return;
        }

        PagoTenant::query()
            ->where('referencia_externa', $referencia)
            ->where('estado', EstadoPago::Pendiente->value)
            ->update(['estado' => EstadoPago::Rechazado->value]);
    }
}
