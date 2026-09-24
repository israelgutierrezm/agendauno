<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\PasarelaNoDisponible;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Ordenes\Exceptions\OrdenNoLiquidable;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
use Illuminate\Support\Facades\DB;

/**
 * Cobra una orden tenant-local con la pasarela del estudio. El cobro en linea es
 * ASINCRONO: crea un pago `pendiente` con la referencia del intento y devuelve los
 * datos de checkout (client_secret/redirect/voucher); el webhook lo confirmara ->
 * fulfillment. El cobro manual/efectivo se aprueba y cumple en el momento.
 * Idempotente por `idempotency_key`. Serializa con lockForUpdate sobre la orden.
 */
class CobrarOrdenTenant
{
    public function __construct(
        private readonly RegistroDePasarelasTenant $registro,
        private readonly FulfillmentTenant $fulfillment,
    ) {}

    public function ejecutar(OrdenTenant $orden, string $proveedor, ?MetodoPago $metodo = null, ?string $idempotencyKey = null, ?string $retorno = null): PagoTenant
    {
        if ($idempotencyKey !== null) {
            $previo = PagoTenant::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($previo instanceof PagoTenant) {
                return $previo;
            }
        }

        if (! $this->registro->activa($proveedor)) {
            throw new PasarelaNoDisponible('La pasarela no esta activa en este estudio.');
        }

        return DB::connection('tenant')->transaction(function () use ($orden, $proveedor, $metodo, $idempotencyKey, $retorno): PagoTenant {
            $bloqueada = OrdenTenant::query()->whereKey($orden->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado !== EstadoOrden::Pendiente) {
                throw new OrdenNoLiquidable('La orden no admite cobro.');
            }

            $pago = PagoTenant::query()->create([
                'orden_id' => $bloqueada->getKey(),
                'proveedor' => $proveedor,
                'metodo' => $metodo?->value,
                'estado' => EstadoPago::Pendiente->value,
                'monto_minor' => $bloqueada->total_minor,
                'moneda' => $bloqueada->moneda,
                'idempotency_key' => $idempotencyKey,
            ]);

            $pago->retorno = $retorno;
            $resultado = $this->registro->resolver($proveedor)->cobrar($pago, $this->registro->llaves($proveedor));
            $pago->checkout = $resultado->datos;

            if ($resultado->esAprobado()) {
                $pago->update(['estado' => EstadoPago::Aprobado->value, 'referencia_externa' => $resultado->referencia]);
                // El fulfillment asienta orden.pagada (recibo, puntos de lealtad).
                $this->fulfillment->cumplir($bloqueada);
            } elseif ($resultado->esPendiente()) {
                // Queda pendiente; el webhook confirmara y hara el fulfillment.
                $pago->update(['referencia_externa' => $resultado->referencia]);
            } else {
                $pago->update(['estado' => EstadoPago::Rechazado->value, 'referencia_externa' => $resultado->referencia]);
            }

            return $pago;
        });
    }
}
