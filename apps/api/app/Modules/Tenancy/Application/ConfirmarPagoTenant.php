<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Pagos\EstadoPago;
use App\Modules\Tenancy\Models\PagoTenant;
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
        private readonly RegistrarEventoTenant $eventos,
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
                $this->fulfillment->cumplir($orden);
                // Evento de dominio (outbox): habilita acumular puntos de lealtad por compra.
                $this->eventos->registrar('orden.pagada', 'orden', $orden->ulid, [
                    'persona_id' => $orden->persona?->ulid,
                    'total_minor' => $orden->total_minor,
                    'orden_id' => $orden->ulid,
                ]);
            }
        });
    }
}
