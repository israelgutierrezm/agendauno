<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\InventarioTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Inventario\Exceptions\StockInsuficiente;
use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventario minorista del estudio, tenant-local (R21): catálogo de artículos y su stock
 * por sucursal (derivado del ledger), con entradas y ajustes. Cada existencia lleva su
 * estado (con stock, stock bajo, sin stock) según el umbral del negocio
 * (`inventario.stock_bajo`, ADR 0042). Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class InventarioTenantController
{
    public function __construct(
        private readonly InventarioTenant $inventario,
        private readonly ResolverAccesoTenant $acceso,
        private readonly ParametrosTenant $parametros,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $articulos = ArticuloTenant::query()->orderByDesc('id')->get();
        $sucursales = $this->mapaSucursales();
        $permitidas = $this->permitidas($request);

        return response()->json([
            'data' => $articulos->map(fn (ArticuloTenant $a): array => $this->presentar($a, $sucursales, $permitidas))->all(),
            // Desde cuántas piezas (o menos) se considera stock bajo.
            'meta' => ['stock_bajo' => $this->parametros->entero('inventario.stock_bajo')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $articulo = ArticuloTenant::query()->create($this->validar($request));

        return response()->json(['data' => $this->presentar($articulo, $this->mapaSucursales(), $this->permitidas($request))], 201);
    }

    public function actualizar(Request $request): JsonResponse
    {
        $articulo = $this->resolver($request);
        $articulo->update($this->validar($request));

        return response()->json(['data' => $this->presentar($articulo->refresh(), $this->mapaSucursales(), $this->permitidas($request))]);
    }

    /**
     * Registra una entrada (reabastecimiento) o un ajuste de inventario del artículo.
     */
    public function movimiento(Request $request): JsonResponse
    {
        $articulo = $this->resolver($request);
        $validado = $request->validate([
            'sucursal_id' => ['required', 'string'],
            'tipo' => ['required', 'in:entrada,ajuste'],
            'cantidad' => ['required', 'integer', 'not_in:0'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $sucursalId = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->value('id');
        abort_if($sucursalId === null, 404);
        $sucursalId = (int) $sucursalId;

        // Alcance por sucursal (R19): un acotado no mueve inventario en una sede ajena.
        $actor = $this->actor($request);
        abort_unless(
            $actor === null || $this->acceso->permiteSucursal($actor, $sucursalId),
            403,
            'No puedes mover inventario en una sucursal que no te corresponde.',
        );

        $tipo = TipoMovimientoInventario::from($validado['tipo']);
        $cantidad = (int) $validado['cantidad'];
        // Entrada siempre suma; ajuste usa el signo tal cual.
        $delta = $tipo === TipoMovimientoInventario::Entrada ? abs($cantidad) : $cantidad;

        if ($this->inventario->stock($articulo->getKey(), $sucursalId) + $delta < 0) {
            throw new StockInsuficiente('El ajuste dejaría el stock en negativo.');
        }

        $this->inventario->registrar(
            $articulo->getKey(),
            $sucursalId,
            $delta,
            $tipo,
            $validado['motivo'] ?? null,
            $this->actor($request),
        );

        return response()->json(['data' => $this->presentar($articulo, $this->mapaSucursales())], 201);
    }

    /**
     * Mapa id interno => {ulid, nombre} de las sucursales, para presentar existencias.
     *
     * @return array<int, array{ulid: string, nombre: string}>
     */
    private function mapaSucursales(): array
    {
        return SucursalTenant::query()
            ->get(['id', 'ulid', 'nombre'])
            ->keyBy('id')
            ->map(fn (SucursalTenant $s): array => ['ulid' => (string) $s->ulid, 'nombre' => (string) $s->nombre])
            ->all();
    }

    private function resolver(Request $request): ArticuloTenant
    {
        return ArticuloTenant::query()->where('ulid', (string) $request->route('articulo'))->firstOrFail();
    }

    private function actor(Request $request): ?Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');

        return $usuario instanceof Usuario ? $usuario : null;
    }

    /**
     * Sucursales a las que el actor está acotado, o `null` (todas). R19.
     *
     * @return list<int>|null
     */
    private function permitidas(Request $request): ?array
    {
        $actor = $this->actor($request);

        return $actor !== null ? $this->acceso->sucursalesPermitidas($actor) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request): array
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:64'],
            'precio_minor' => ['required', 'integer', 'min:0'],
            'moneda' => ['nullable', 'string', 'size:3'],
            'activo' => ['boolean'],
        ]);

        return [
            'nombre' => $validado['nombre'],
            'sku' => $validado['sku'] ?? null,
            'precio_minor' => (int) $validado['precio_minor'],
            'moneda' => strtoupper($validado['moneda'] ?? 'MXN'),
            'activo' => (bool) ($validado['activo'] ?? true),
        ];
    }

    /**
     * @param  array<int, array{ulid: string, nombre: string}>  $sucursales
     * @param  list<int>|null  $permitidas  sucursales visibles para el actor (null = todas)
     * @return array<string, mixed>
     */
    private function presentar(ArticuloTenant $articulo, array $sucursales, ?array $permitidas = null): array
    {
        $porSucursal = $this->inventario->stockPorSucursal($articulo->getKey());
        $umbral = $this->parametros->entero('inventario.stock_bajo');
        $existencias = [];
        $total = 0;
        foreach ($porSucursal as $sucursalId => $stock) {
            // Alcance por sucursal (R19): un acotado no ve el stock de otras sedes.
            if ($permitidas !== null && ! in_array((int) $sucursalId, $permitidas, true)) {
                continue;
            }
            $existencias[] = [
                'sucursal_id' => $sucursales[$sucursalId]['ulid'] ?? null,
                'sucursal' => $sucursales[$sucursalId]['nombre'] ?? '—',
                'stock' => $stock,
                'estado' => self::estadoStock($stock, $umbral),
            ];
            $total += $stock;
        }

        return [
            'id' => $articulo->ulid,
            'nombre' => $articulo->nombre,
            'sku' => $articulo->sku,
            'precio_minor' => $articulo->precio_minor,
            'moneda' => $articulo->moneda,
            'activo' => $articulo->activo,
            'stock_total' => $total,
            'estado_stock' => self::estadoStock($total, $umbral),
            'existencias' => $existencias,
        ];
    }

    /** Sin stock, stock bajo (el umbral del negocio o menos) o con stock. */
    private static function estadoStock(int $stock, int $umbral): string
    {
        return match (true) {
            $stock <= 0 => 'sin_stock',
            $stock <= $umbral => 'bajo',
            default => 'con_stock',
        };
    }
}
