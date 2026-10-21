<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\InventarioTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Inventario\TipoMovimientoInventario;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\CategoriaArticuloTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\ExtraArticuloTenant;
use App\Modules\Tenancy\Models\MovimientoInventarioTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\DiscosDeArchivos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

    /**
     * Los productos con su stock. Con `page`, por páginas y con búsqueda y filtros en
     * el servidor (nombre o SKU, a la venta, estado del stock, categoría), contando el
     * stock en `sucursal_id` si se pide; sin `page`, todos (el mostrador). El stock y
     * los extras de la página salen en consultas agrupadas, no una por producto.
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'venta' => ['nullable', 'in:activo,inactivo'],
            'stock' => ['nullable', 'in:con_stock,bajo,sin_stock'],
            'categoria' => ['nullable', 'string', 'max:40'],
            'sucursal_id' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $sucursales = $this->mapaSucursales();
        $permitidas = $this->permitidas($request);
        $umbral = $this->parametros->entero('inventario.stock_bajo');
        // Dónde se cuenta el stock de los filtros e indicadores: la sucursal pedida (si
        // le corresponde) o todas las suyas.
        $contarEn = $permitidas;
        if (($filtros['sucursal_id'] ?? '') !== '') {
            $sucursalId = SucursalTenant::query()->where('ulid', $filtros['sucursal_id'])->value('id');
            abort_if($sucursalId === null, 404);
            abort_unless($permitidas === null || in_array((int) $sucursalId, $permitidas, true), 403);
            $contarEn = [(int) $sucursalId];
        }
        // Filtra por el stock (suma de su ledger en esas sucursales) comparado con un valor.
        $porStock = function ($consulta, string $operador, int $valor) use ($contarEn) {
            $suma = MovimientoInventarioTenant::query()->toBase()
                ->selectRaw('coalesce(sum(cantidad), 0)')
                ->whereColumn('movimientos_inventario.articulo_id', 'articulos.id')
                ->when($contarEn !== null, fn ($q) => $q->whereIn('sucursal_id', $contarEn));

            return $consulta->whereRaw('('.$suma->toSql().') '.$operador.' ?', [...$suma->getBindings(), $valor]);
        };
        $buscar = trim((string) ($filtros['q'] ?? ''));
        $categoria = (string) ($filtros['categoria'] ?? '');

        $consulta = ArticuloTenant::query()
            ->with(['categoria', 'extras' => fn ($q) => $q->where('activo', true)])
            ->when($buscar !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('nombre', 'like', '%'.$buscar.'%')
                ->orWhere('sku', 'like', '%'.$buscar.'%')))
            ->when(isset($filtros['venta']), fn ($q) => $q->where('activo', $filtros['venta'] === 'activo'))
            ->when($categoria === '-', fn ($q) => $q->whereNull('categoria_id'))
            ->when($categoria !== '' && $categoria !== '-', fn ($q) => $q->where(
                'categoria_id',
                (int) CategoriaArticuloTenant::query()->where('ulid', $categoria)->value('id'),
            ))
            ->when(($filtros['stock'] ?? null) === 'sin_stock', fn ($q) => $porStock($q, '<=', 0))
            ->when(($filtros['stock'] ?? null) === 'bajo', fn ($q) => $porStock($porStock($q, '>', 0), '<=', $umbral))
            ->when(($filtros['stock'] ?? null) === 'con_stock', fn ($q) => $porStock($q, '>', $umbral))
            ->orderByDesc('id');

        $pagina = isset($filtros['page'])
            ? $consulta->paginate((int) ($filtros['per_page'] ?? 25), ['*'], 'page', (int) $filtros['page'])
            : null;
        $articulos = $pagina !== null ? $pagina->getCollection() : $consulta->get();
        $existencias = $this->inventario->stockPorArticulos($articulos->modelKeys(), $permitidas);

        // Desde cuántas piezas (o menos) se considera stock bajo.
        $meta = ['stock_bajo' => $umbral];
        if ($pagina !== null) {
            $meta += [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                // Para distinguir «aún no hay productos» de «ninguno coincide».
                'total_articulos' => ArticuloTenant::query()->count(),
                'resumen' => $this->resumenStock($contarEn, $umbral),
            ];
        }

        return response()->json([
            'data' => $articulos->map(fn (ArticuloTenant $a): array => $this->presentar(
                $a, $sucursales, $permitidas, $existencias[(int) $a->getKey()] ?? [], $umbral,
            ))->all(),
            'meta' => $meta,
        ]);
    }

    /**
     * Los indicadores del inventario (productos a la venta y cuántos con stock, bajo o
     * sin stock) contando en esas sucursales: dos consultas, no una por producto.
     *
     * @param  list<int>|null  $sucursales
     * @return array{productos: int, con_stock: int, bajo: int, sin_stock: int}
     */
    private function resumenStock(?array $sucursales, int $umbral): array
    {
        $totales = MovimientoInventarioTenant::query()
            ->when($sucursales !== null, fn ($q) => $q->whereIn('sucursal_id', $sucursales))
            ->groupBy('articulo_id')
            ->selectRaw('articulo_id, sum(cantidad) as total')
            ->pluck('total', 'articulo_id');
        $resumen = ['productos' => 0, 'con_stock' => 0, 'bajo' => 0, 'sin_stock' => 0];
        foreach (ArticuloTenant::query()->where('activo', true)->pluck('id') as $id) {
            $resumen['productos']++;
            $resumen[self::estadoStock((int) ($totales[$id] ?? 0), $umbral)]++;
        }

        return $resumen;
    }

    /**
     * El historial de entradas, ajustes y ventas de un producto, por páginas y del más
     * reciente: cuánto, por qué, quién y en qué sucursal (solo las suyas, R19).
     */
    public function movimientos(Request $request): JsonResponse
    {
        $articulo = $this->resolver($request);
        $filtros = $request->validate([
            'tipo' => ['nullable', Rule::enum(TipoMovimientoInventario::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $permitidas = $this->permitidas($request);
        $pagina = MovimientoInventarioTenant::query()
            ->where('articulo_id', $articulo->getKey())
            ->when($permitidas !== null, fn ($q) => $q->whereIn('sucursal_id', $permitidas))
            ->when(isset($filtros['tipo']), fn ($q) => $q->where('tipo', $filtros['tipo']))
            ->orderByDesc('id')
            ->paginate((int) ($filtros['per_page'] ?? 20), ['*'], 'page', (int) ($filtros['page'] ?? 1));
        $sucursales = $this->mapaSucursales();
        $quienes = Usuario::withTrashed()
            ->whereIn('id', $pagina->getCollection()->pluck('usuario_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        return response()->json([
            'data' => $pagina->getCollection()->map(static fn (MovimientoInventarioTenant $m): array => [
                'id' => $m->ulid,
                'tipo' => $m->tipo->value,
                'cantidad' => $m->cantidad,
                'motivo' => $m->motivo,
                'sucursal' => $sucursales[$m->sucursal_id]['nombre'] ?? '—',
                'quien' => $m->usuario_id !== null ? ($quienes[$m->usuario_id] ?? null) : null,
                // De una venta del mostrador (o su anulación), no capturado a mano.
                'de_venta' => $m->venta_pos_id !== null,
                'creado_en' => $m->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
            ],
        ]);
    }

    /**
     * Crea el producto con sus extras: todo se valida antes y se guarda junto (si algo
     * no es válido, no queda nada a medias que se duplique al volver a guardar).
     */
    public function store(Request $request): JsonResponse
    {
        [$datos, $extras] = $this->validarTodo($request);
        $articulo = DB::connection('tenant')->transaction(function () use ($datos, $extras): ArticuloTenant {
            $articulo = ArticuloTenant::query()->create($datos);
            $this->guardarExtras($articulo, $extras);

            return $articulo;
        });

        return response()->json(['data' => $this->presentar($articulo, $this->mapaSucursales(), $this->permitidas($request))], 201);
    }

    public function actualizar(Request $request): JsonResponse
    {
        $articulo = $this->resolver($request);
        [$datos, $extras] = $this->validarTodo($request);
        DB::connection('tenant')->transaction(function () use ($articulo, $datos, $extras): void {
            $articulo->update($datos);
            $this->guardarExtras($articulo, $extras);
        });

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
            DiscosDeArchivos::nombrePublico(),
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
            DiscosDeArchivos::publico()->delete($articulo->foto_ruta);
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
            // Un ajuste a mano dice por qué (conteo, merma, caducidad…): queda en su
            // historial con quién lo hizo.
            'motivo' => ['nullable', 'required_if:tipo,ajuste', 'string', 'min:3', 'max:255'],
        ], [
            'motivo.required_if' => 'Escribe el motivo del ajuste.',
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

        $this->inventario->ajustar(
            (int) $articulo->getKey(),
            $sucursalId,
            $delta,
            $tipo,
            $validado['motivo'] ?? null,
            $this->actor($request),
        );

        // Con el mismo alcance que el listado: un acotado no ve el stock de otras sedes.
        return response()->json(['data' => $this->presentar($articulo, $this->mapaSucursales(), $this->permitidas($request))], 201);
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
     * Producto y extras validados juntos (todos los errores a la vez, cada uno en su
     * campo); los extras son null si no vienen (no se tocan).
     *
     * @return array{0: array<string, mixed>, 1: list<array{id?: string|null, nombre: string, precio_minor: int}>|null}
     */
    private function validarTodo(Request $request): array
    {
        $reglas = $this->reglas();
        if ($request->has('extras')) {
            $reglas += [
                'extras' => ['present', 'array', 'max:30'],
                'extras.*.id' => ['nullable', 'string'],
                'extras.*.nombre' => ['required', 'string', 'max:80'],
                'extras.*.precio_minor' => ['required', 'integer', 'min:0', 'max:100000000'],
            ];
        }
        $validado = $request->validate($reglas);
        /** @var list<array{id?: string|null, nombre: string, precio_minor: int}>|null $extras */
        $extras = $request->has('extras') ? array_values($validado['extras']) : null;

        return [$this->datos($request, $validado), $extras];
    }

    /**
     * Guarda los extras ya validados (ADR 0139): los que traen `id` se actualizan, los
     * nuevos se crean y los que ya no vienen se apagan (las ventas guardan los suyos).
     *
     * @param  list<array{id?: string|null, nombre: string, precio_minor: int}>|null  $extras
     */
    private function guardarExtras(ArticuloTenant $articulo, ?array $extras): void
    {
        if ($extras === null) {
            return;
        }
        $vigentes = [];
        foreach ($extras as $orden => $dato) {
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
     * @return array<string, list<mixed>>
     */
    private function reglas(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:64'],
            'precio_minor' => ['required', 'integer', 'min:0'],
            'moneda' => ['nullable', 'string', 'size:3', app(RegionNegocioTenant::class)->reglaMoneda()],
            'activo' => ['boolean'],
            // Su categoría (id público); null la quita.
            'categoria_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            // Se ofrece para apartar al reservar una clase (ADR 0194).
            'ofrecer_al_reservar' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validado
     * @return array<string, mixed>
     */
    private function datos(Request $request, array $validado): array
    {
        $datos = [
            'nombre' => $validado['nombre'],
            'sku' => $validado['sku'] ?? null,
            'precio_minor' => (int) $validado['precio_minor'],
            'moneda' => strtoupper($validado['moneda'] ?? app(ParametrosTenant::class)->moneda()),
            'activo' => (bool) ($validado['activo'] ?? true),
        ];
        if ($request->has('ofrecer_al_reservar')) {
            $datos['ofrecer_al_reservar'] = (bool) $validado['ofrecer_al_reservar'];
        }
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
     * @param  array<int, int>|null  $porSucursal  su stock por sucursal, si ya se calculó
     * @return array<string, mixed>
     */
    private function presentar(
        ArticuloTenant $articulo,
        array $sucursales,
        ?array $permitidas = null,
        ?array $porSucursal = null,
        ?int $umbral = null,
    ): array {
        // En un listado llegan ya calculados (una consulta para todos).
        $porSucursal ??= $this->inventario->stockPorSucursal($articulo->getKey());
        $umbral ??= $this->parametros->entero('inventario.stock_bajo');
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
            'ofrecer_al_reservar' => $articulo->ofrecer_al_reservar,
            // Lo que se le puede agregar (ADR 0139).
            'extras' => ($articulo->relationLoaded('extras') ? $articulo->extras : $articulo->extras()->where('activo', true)->get())->map(static fn (ExtraArticuloTenant $e): array => [
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
