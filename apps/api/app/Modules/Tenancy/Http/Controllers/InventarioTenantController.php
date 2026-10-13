<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\InventarioTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Inventario\Exceptions\StockInsuficiente;
use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\CategoriaArticuloTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ExtraArticuloTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Inventario minorista del estudio, tenant-local (R21): catálogo de artículos y su stock
 * por sucursal (derivado del ledger), con entradas y ajustes. Cada existencia lleva su
 * estado (con stock, stock bajo, sin stock) según el umbral del negocio
 * (`inventario.stock_bajo`, ADR 0042). Opera SIEMPRE sobre la BD del estudio resuelto.
 * Cada producto puede llevar una categoría del negocio y su foto (mostrador).
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
        $articulos = ArticuloTenant::query()->with('categoria')->orderByDesc('id')->get();
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
        $this->guardarExtras($request, $articulo);

        return response()->json(['data' => $this->presentar($articulo, $this->mapaSucursales(), $this->permitidas($request))], 201);
    }

    public function actualizar(Request $request): JsonResponse
    {
        $articulo = $this->resolver($request);
        $articulo->update($this->validar($request));
        $this->guardarExtras($request, $articulo);

        return response()->json(['data' => $this->presentar($articulo->refresh(), $this->mapaSucursales(), $this->permitidas($request))]);
    }

    /** Su foto (PNG, JPG o WebP de hasta 4 MB): reemplaza la anterior. */
    public function subirFoto(Request $request): JsonResponse
    {
        $articulo = $this->resolver($request);
        $request->validate([
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        $archivo = $request->file('foto');
        abort_unless($archivo instanceof UploadedFile, 422);

        $ruta = $archivo->storeAs(
            'productos/'.$estudio->getKey(),
            Str::lower(Str::random(40)).'.'.$archivo->extension(),
            'public',
        );
        $this->borrarFoto($articulo);
        $articulo->update(['foto_ruta' => (string) $ruta]);

        return response()->json(['data' => $this->presentar($articulo->refresh(), $this->mapaSucursales(), $this->permitidas($request))]);
    }

    public function eliminarFoto(Request $request): JsonResponse
    {
        $articulo = $this->resolver($request);
        $this->borrarFoto($articulo);
        $articulo->update(['foto_ruta' => null]);

        return response()->json(['data' => $this->presentar($articulo->refresh(), $this->mapaSucursales(), $this->permitidas($request))]);
    }

    private function borrarFoto(ArticuloTenant $articulo): void
    {
        if ($articulo->foto_ruta !== null && $articulo->foto_ruta !== '') {
            Storage::disk('public')->delete($articulo->foto_ruta);
        }
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
     * Los extras del artículo, si vienen (ADR 0139): los que traen `id` se actualizan,
     * los nuevos se crean y los que ya no vienen se apagan (las ventas guardan los suyos).
     */
    private function guardarExtras(Request $request, ArticuloTenant $articulo): void
    {
        if (! $request->has('extras')) {
            return;
        }
        $validado = $request->validate([
            'extras' => ['present', 'array', 'max:30'],
            'extras.*.id' => ['nullable', 'string'],
            'extras.*.nombre' => ['required', 'string', 'max:80'],
            'extras.*.precio_minor' => ['required', 'integer', 'min:0', 'max:100000000'],
        ]);
        $vigentes = [];
        foreach (array_values($validado['extras']) as $orden => $dato) {
            $extra = isset($dato['id']) && $dato['id'] !== ''
                ? ExtraArticuloTenant::query()->where('articulo_id', $articulo->getKey())->where('ulid', $dato['id'])->first()
                : null;
            $extra ??= new ExtraArticuloTenant(['articulo_id' => $articulo->getKey()]);
            $extra->fill(['nombre' => trim((string) $dato['nombre']), 'precio_minor' => (int) $dato['precio_minor'], 'activo' => true, 'orden' => $orden])->save();
            $vigentes[] = $extra->getKey();
        }
        ExtraArticuloTenant::query()->where('articulo_id', $articulo->getKey())->whereNotIn('id', $vigentes)->update(['activo' => false]);
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
            'moneda' => ['nullable', 'string', 'size:3', app(RegionNegocioTenant::class)->reglaMoneda()],
            'activo' => ['boolean'],
            // Su categoría (id público); null la quita.
            'categoria_id' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $datos = [
            'nombre' => $validado['nombre'],
            'sku' => $validado['sku'] ?? null,
            'precio_minor' => (int) $validado['precio_minor'],
            'moneda' => strtoupper($validado['moneda'] ?? app(ParametrosTenant::class)->moneda()),
            'activo' => (bool) ($validado['activo'] ?? true),
        ];
        // La categoría solo se toca si viene (editar sin ella no la quita).
        if ($request->has('categoria_id')) {
            $datos['categoria_id'] = $this->categoriaId($validado['categoria_id'] ?? null);
        }

        return $datos;
    }

    private function categoriaId(?string $ulid): ?int
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }
        $categoria = CategoriaArticuloTenant::query()->where('ulid', $ulid)->first();
        if (! $categoria instanceof CategoriaArticuloTenant) {
            throw ValidationException::withMessages(['categoria_id' => ['Esa categoría no existe.']]);
        }

        return (int) $categoria->getKey();
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
            // Su categoría (con su orden, para agrupar en el mostrador) y su foto.
            'categoria' => $articulo->categoria instanceof CategoriaArticuloTenant ? [
                'id' => $articulo->categoria->ulid,
                'nombre' => $articulo->categoria->nombre,
                'orden' => $articulo->categoria->orden,
            ] : null,
            'foto_url' => $articulo->fotoUrl(),
            // Lo que se le puede agregar (ADR 0139).
            'extras' => $articulo->extras()->where('activo', true)->get()->map(static fn (ExtraArticuloTenant $e): array => [
                'id' => $e->ulid, 'nombre' => $e->nombre, 'precio_minor' => $e->precio_minor,
            ])->all(),
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
