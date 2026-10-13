<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Inventario\Exceptions\StockInsuficiente;
use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\MovimientoInventarioTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Inventario tenant-local (R21): stock por (artículo, sucursal) DERIVADO del ledger
 * `movimientos_inventario` (suma de deltas, nunca almacenado) y registro de entradas /
 * salidas / ajustes. Consistente con el ledger de créditos (auditable).
 */
class InventarioTenant
{
    /**
     * Stock actual de un artículo en una sucursal (suma de deltas).
     */
    public function stock(int $articuloId, int $sucursalId): int
    {
        return (int) MovimientoInventarioTenant::query()
            ->where('articulo_id', $articuloId)
            ->where('sucursal_id', $sucursalId)
            ->sum('cantidad');
    }

    /**
     * Stock de un artículo por sucursal (mapa sucursal_id => stock).
     *
     * @return array<int, int>
     */
    public function stockPorSucursal(int $articuloId): array
    {
        return MovimientoInventarioTenant::query()
            ->where('articulo_id', $articuloId)
            ->groupBy('sucursal_id')
            ->selectRaw('sucursal_id, sum(cantidad) as total')
            ->pluck('total', 'sucursal_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * Una entrada o un ajuste a mano, sin dejar el stock en negativo. Bloquea el
     * artículo (como la venta del mostrador) durante la revisión del saldo y el
     * asiento: dos ajustes a la vez no se aprueban sobre el mismo saldo.
     *
     * @throws StockInsuficiente si dejaría el stock en negativo
     */
    public function ajustar(
        int $articuloId,
        int $sucursalId,
        int $delta,
        TipoMovimientoInventario $tipo,
        ?string $motivo = null,
        ?Usuario $actor = null,
    ): MovimientoInventarioTenant {
        return DB::connection('tenant')->transaction(function () use ($articuloId, $sucursalId, $delta, $tipo, $motivo, $actor): MovimientoInventarioTenant {
            ArticuloTenant::query()->whereKey($articuloId)->lockForUpdate()->firstOrFail();
            if ($delta < 0 && $this->stock($articuloId, $sucursalId) + $delta < 0) {
                throw new StockInsuficiente('El ajuste dejaría el stock en negativo.');
            }

            return $this->registrar($articuloId, $sucursalId, $delta, $tipo, $motivo, $actor);
        });
    }

    /**
     * Asienta un movimiento de inventario (delta con signo) y lo devuelve.
     */
    public function registrar(
        int $articuloId,
        int $sucursalId,
        int $delta,
        TipoMovimientoInventario $tipo,
        ?string $motivo = null,
        ?Usuario $actor = null,
        ?int $ventaPosId = null,
    ): MovimientoInventarioTenant {
        return MovimientoInventarioTenant::query()->create([
            'articulo_id' => $articuloId,
            'sucursal_id' => $sucursalId,
            'tipo' => $tipo->value,
            'cantidad' => $delta,
            'motivo' => $motivo,
            'venta_pos_id' => $ventaPosId,
            'usuario_id' => $actor?->getKey(),
        ]);
    }
}
