<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Modules\Tenancy\Models\MovimientoInventarioTenant;
use App\Modules\Tenancy\Models\Usuario;

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
