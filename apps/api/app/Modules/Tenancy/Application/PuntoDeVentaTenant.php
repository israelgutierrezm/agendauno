<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Inventario\Exceptions\StockInsuficiente;
use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VentaPosTenant;
use Illuminate\Support\Facades\DB;

/**
 * Punto de venta minorista tenant-local (R21): registra un ticket de caja y descuenta
 * stock (movimientos de salida) de forma atómica. Bloquea el artículo (lockForUpdate)
 * para serializar ventas concurrentes del mismo artículo y no vender de más.
 */
class PuntoDeVentaTenant
{
    public function __construct(private readonly InventarioTenant $inventario) {}

    /**
     * @param  list<array{articulo: ArticuloTenant, cantidad: int}>  $items
     */
    public function vender(SucursalTenant $sucursal, array $items, string $metodo, ?Usuario $actor = null): VentaPosTenant
    {
        return DB::connection('tenant')->transaction(function () use ($sucursal, $items, $metodo, $actor): VentaPosTenant {
            $moneda = $items[0]['articulo']->moneda;

            $venta = VentaPosTenant::query()->create([
                'sucursal_id' => $sucursal->getKey(),
                'total_minor' => 0,
                'moneda' => $moneda,
                'metodo_pago' => $metodo,
                'usuario_id' => $actor?->getKey(),
            ]);

            $total = 0;
            foreach ($items as $item) {
                // Serializa ventas concurrentes del mismo articulo (no-sobreventa).
                $articulo = ArticuloTenant::query()->whereKey($item['articulo']->getKey())->lockForUpdate()->firstOrFail();
                $cantidad = max(1, $item['cantidad']);

                $stock = $this->inventario->stock($articulo->getKey(), $sucursal->getKey());
                if ($stock < $cantidad) {
                    throw new StockInsuficiente("Stock insuficiente de {$articulo->nombre} en la sucursal.");
                }

                $subtotal = $articulo->precio_minor * $cantidad;
                $total += $subtotal;

                $venta->lineas()->create([
                    'articulo_id' => $articulo->getKey(),
                    'cantidad' => $cantidad,
                    'precio_unitario_minor' => $articulo->precio_minor,
                    'subtotal_minor' => $subtotal,
                ]);

                $this->inventario->registrar(
                    $articulo->getKey(),
                    $sucursal->getKey(),
                    -$cantidad,
                    TipoMovimientoInventario::Salida,
                    'Venta POS',
                    $actor,
                    $venta->getKey(),
                );
            }

            $venta->update(['total_minor' => $total]);

            return $venta->refresh();
        });
    }
}
