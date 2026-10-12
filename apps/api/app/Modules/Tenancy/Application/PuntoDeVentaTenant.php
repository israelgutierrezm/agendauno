<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Inventario\Exceptions\StockInsuficiente;
use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\ExtraArticuloTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VentaPosTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Punto de venta minorista tenant-local (R21): registra un ticket de caja y descuenta
 * stock (movimientos de salida) de forma atómica. Bloquea el artículo (lockForUpdate)
 * para serializar ventas concurrentes del mismo artículo y no vender de más.
 */
class PuntoDeVentaTenant
{
    public function __construct(private readonly InventarioTenant $inventario) {}

    /**
     * @param  list<array{articulo: ArticuloTenant, cantidad: int, extras?: list<ExtraArticuloTenant>}>  $items
     */
    public function vender(SucursalTenant $sucursal, array $items, string $metodo, ?Usuario $actor = null, int $propinaMinor = 0, ?Usuario $propinaPara = null, ?int $recibidoMinor = null): VentaPosTenant
    {
        return DB::connection('tenant')->transaction(function () use ($sucursal, $items, $metodo, $actor, $propinaMinor, $propinaPara, $recibidoMinor): VentaPosTenant {
            $moneda = $items[0]['articulo']->moneda;

            // La propina va aparte de lo vendido: no es ingreso del negocio (ADR 0139).
            $venta = VentaPosTenant::query()->create([
                'sucursal_id' => $sucursal->getKey(),
                'total_minor' => 0,
                'moneda' => $moneda,
                'metodo_pago' => $metodo,
                'usuario_id' => $actor?->getKey(),
                'propina_minor' => max(0, $propinaMinor),
                'propina_para_id' => $propinaMinor > 0 ? $propinaPara?->getKey() : null,
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

                // Sus extras, con el precio de hoy (congelados en la línea; ADR 0139).
                $extras = array_map(static fn (ExtraArticuloTenant $e): array => [
                    'id' => $e->ulid, 'nombre' => $e->nombre, 'precio_minor' => $e->precio_minor,
                ], $item['extras'] ?? []);
                $extrasMinor = array_sum(array_column($extras, 'precio_minor'));
                $subtotal = ($articulo->precio_minor + $extrasMinor) * $cantidad;
                $total += $subtotal;

                $venta->lineas()->create([
                    'articulo_id' => $articulo->getKey(),
                    'cantidad' => $cantidad,
                    'precio_unitario_minor' => $articulo->precio_minor,
                    'subtotal_minor' => $subtotal,
                    'extras' => $extras === [] ? null : $extras,
                    'extras_minor' => $extrasMinor,
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

            // En efectivo: lo que entregó alcanza para lo vendido y la propina (el cambio).
            if ($recibidoMinor !== null && $recibidoMinor < $total + $venta->propina_minor) {
                throw ValidationException::withMessages(['recibido_minor' => ['Lo que recibiste no alcanza para cobrar la venta.']]);
            }
            $venta->update(['total_minor' => $total, 'recibido_minor' => $metodo === 'efectivo' ? $recibidoMinor : null]);

            return $venta->refresh();
        });
    }
}
