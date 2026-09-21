<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;

/**
 * Fulfillment de una orden tenant-local: la marca pagada y concede un derecho por
 * cada unidad de cada linea al beneficiario (o comprador). Idempotente: una orden ya
 * pagada no vuelve a conceder. Compartido por la liquidacion manual (ventanilla) y
 * por la confirmacion de un pago con pasarela (webhook).
 */
class FulfillmentTenant
{
    public function __construct(private readonly MembresiasTenant $membresias) {}

    public function cumplir(OrdenTenant $orden): void
    {
        if ($orden->estado === EstadoOrden::Pagada) {
            return;
        }

        $orden->update(['estado' => EstadoOrden::Pagada->value, 'pagada_en' => now()]);

        // Orden de RENOVACIÓN (cobro recurrente): solo cobra; el entitlement lo mantiene
        // el motor de ciclos sobre el acuerdo existente. No se crea un acuerdo nuevo.
        if ($orden->renueva_acuerdo_id !== null) {
            return;
        }

        $orden->loadMissing(['lineas.producto', 'lineas.beneficiario', 'persona']);

        foreach ($orden->lineas as $linea) {
            $beneficiario = $linea->beneficiario ?? $orden->persona;
            $producto = $linea->producto;

            if (! $beneficiario instanceof PersonaTenant || ! $producto instanceof ProductoTenant) {
                continue;
            }

            for ($i = 0; $i < $linea->cantidad; $i++) {
                $acuerdo = $this->membresias->venderProducto($beneficiario, $producto);
                $acuerdo->update(['linea_orden_id' => $linea->getKey()]);
            }
        }
    }
}
