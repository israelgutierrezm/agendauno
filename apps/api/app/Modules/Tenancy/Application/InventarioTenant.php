<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Inventario\Exceptions\StockInsuficiente;
use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\MovimientoInventarioTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
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
     * Stock de varios artículos por sucursal en una sola consulta (para un listado):
     * mapa articulo_id => (sucursal_id => stock). Con `$sucursales`, solo esas.
     *
     * @param  list<int>  $articulos
     * @param  list<int>|null  $sucursales
     * @return array<int, array<int, int>>
     */
    public function stockPorArticulos(array $articulos, ?array $sucursales = null): array
    {
        if ($articulos === []) {
            return [];
        }
        $mapa = [];
        MovimientoInventarioTenant::query()
            ->whereIn('articulo_id', $articulos)
            ->when($sucursales !== null, fn ($q) => $q->whereIn('sucursal_id', $sucursales))
            ->groupBy('articulo_id', 'sucursal_id')
            ->selectRaw('articulo_id, sucursal_id, sum(cantidad) as total')
            ->get()
            ->each(function (MovimientoInventarioTenant $fila) use (&$mapa): void {
                $mapa[(int) $fila->articulo_id][(int) $fila->sucursal_id] = (int) $fila->getAttribute('total');
            });

        return $mapa;
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
     * Lo que una orden cobrada sacó del inventario (los artículos de un presupuesto;
     * ADR 0127) vuelve a la sede de la orden al anular su cobro o devolverla completa
     * (ADR 0166). Bloquea cada artículo, como la venta del mostrador.
     */
    public function devolverDeOrden(OrdenTenant $orden, string $motivo, ?Usuario $actor = null): void
    {
        if ($orden->sucursal_id === null) {
            return;
        }
        foreach ($orden->lineas()->whereNotNull('articulo_id')->orderBy('articulo_id')->get() as $linea) {
            $articuloId = (int) $linea->getAttribute('articulo_id');
            ArticuloTenant::query()->whereKey($articuloId)->lockForUpdate()->first();
            $this->registrar($articuloId, (int) $orden->sucursal_id, max(1, (int) $linea->cantidad), TipoMovimientoInventario::Entrada, $motivo, $actor);
        }
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
