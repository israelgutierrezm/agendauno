<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\LibroMayorTenant;
use App\Modules\Tenancy\Application\MembresiasTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Membresias\PoliticaReset;
use App\Modules\Tenancy\Membresias\PoliticaRollover;
use App\Modules\Tenancy\Membresias\TipoProducto;
use App\Modules\Tenancy\Models\ActividadTenant;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Membresias del estudio (data plane del tenant): productos vendibles, venta
 * (acuerdo → derecho + concesion de creditos) y consulta de derechos con su saldo
 * derivado del ledger. Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class MembresiasTenantController
{
    private const LIMITE = 200;

    public function __construct(
        private readonly MembresiasTenant $membresias,
        private readonly LibroMayorTenant $libro,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function productos(Request $request): JsonResponse
    {
        // Por defecto solo vendibles (no archivados). El editor pide `?incluir=todos`
        // para poder ver y reactivar los archivados.
        $productos = ProductoTenant::query()
            ->when((string) $request->query('incluir') !== 'todos', fn ($q) => $q->where('archivado', false))
            ->with(['actividad', 'sucursal'])
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        return response()->json([
            'data' => $productos->map(fn (ProductoTenant $producto): array => $this->presentarProducto($producto))->all(),
        ]);
    }

    public function crearProducto(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'tipo' => ['required', Rule::enum(TipoProducto::class)],
            'precio_minor' => ['required', 'integer', 'min:0'],
            'moneda' => ['required', 'string', 'size:3'],
            'ilimitado' => ['boolean'],
            'creditos_incluidos' => ['nullable', 'integer', 'min:0'],
            'vigencia_dias' => ['nullable', 'integer', 'min:1'],
            'politica_reset' => ['nullable', Rule::enum(PoliticaReset::class)],
            'unidades_por_ciclo' => ['nullable', 'integer', 'min:0'],
            'politica_rollover' => ['nullable', Rule::enum(PoliticaRollover::class)],
            'rollover_max' => ['nullable', 'integer', 'min:0'],
            'actividad_id' => ['nullable', 'string'],
            'sucursal_id' => ['nullable', 'string'],
        ]);

        $producto = $this->membresias->crearProducto(
            $validado['nombre'],
            TipoProducto::from($validado['tipo']),
            (int) $validado['precio_minor'],
            $validado['moneda'],
            (bool) ($validado['ilimitado'] ?? false),
            isset($validado['creditos_incluidos']) ? (int) $validado['creditos_incluidos'] : null,
            isset($validado['politica_reset']) ? PoliticaReset::from($validado['politica_reset']) : PoliticaReset::Ninguno,
            isset($validado['unidades_por_ciclo']) ? (int) $validado['unidades_por_ciclo'] : null,
            isset($validado['politica_rollover']) ? PoliticaRollover::from($validado['politica_rollover']) : PoliticaRollover::Ninguno,
            isset($validado['rollover_max']) ? (int) $validado['rollover_max'] : null,
            $this->resolverId(ActividadTenant::class, $validado['actividad_id'] ?? null),
            $this->resolverId(SucursalTenant::class, $validado['sucursal_id'] ?? null),
            isset($validado['vigencia_dias']) ? (int) $validado['vigencia_dias'] : null,
        );

        return response()->json(['data' => $this->presentarProducto($producto)], 201);
    }

    /**
     * Editor completo: actualiza la plantilla del producto (precio, acceso, vigencia,
     * ciclos/rollover, restricciones) o lo archiva/reactiva. Solo cambia ventas futuras
     * (no toca acuerdos ya vendidos). Cambio sensible → queda en la bitácora.
     */
    public function actualizarProducto(Request $request): JsonResponse
    {
        $producto = ProductoTenant::query()->where('ulid', (string) $request->route('producto'))->firstOrFail();

        $validado = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255'],
            'tipo' => ['sometimes', Rule::enum(TipoProducto::class)],
            'precio_minor' => ['sometimes', 'integer', 'min:0'],
            'moneda' => ['sometimes', 'string', 'size:3'],
            'ilimitado' => ['sometimes', 'boolean'],
            'creditos_incluidos' => ['nullable', 'integer', 'min:0'],
            'vigencia_dias' => ['nullable', 'integer', 'min:1'],
            'politica_reset' => ['sometimes', Rule::enum(PoliticaReset::class)],
            'unidades_por_ciclo' => ['nullable', 'integer', 'min:0'],
            'politica_rollover' => ['sometimes', Rule::enum(PoliticaRollover::class)],
            'rollover_max' => ['nullable', 'integer', 'min:0'],
            'archivado' => ['sometimes', 'boolean'],
            'actividad_id' => ['sometimes', 'nullable', 'string'],
            'sucursal_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $atributos = [];
        foreach (['nombre', 'precio_minor', 'moneda', 'ilimitado', 'creditos_incluidos', 'vigencia_dias', 'unidades_por_ciclo', 'rollover_max', 'archivado'] as $campo) {
            if ($request->has($campo)) {
                $atributos[$campo] = $validado[$campo] ?? null;
            }
        }
        if ($request->has('tipo')) {
            $atributos['tipo'] = TipoProducto::from($validado['tipo']);
        }
        if ($request->has('politica_reset')) {
            $atributos['politica_reset'] = PoliticaReset::from($validado['politica_reset']);
        }
        if ($request->has('politica_rollover')) {
            $atributos['politica_rollover'] = PoliticaRollover::from($validado['politica_rollover']);
        }
        if ($request->has('actividad_id')) {
            $atributos['actividad_id'] = $this->resolverId(ActividadTenant::class, $validado['actividad_id'] ?? null);
        }
        if ($request->has('sucursal_id')) {
            $atributos['sucursal_id'] = $this->resolverId(SucursalTenant::class, $validado['sucursal_id'] ?? null);
        }

        $antes = $producto->only(['nombre', 'precio_minor', 'ilimitado', 'creditos_incluidos', 'vigencia_dias', 'politica_reset', 'unidades_por_ciclo', 'politica_rollover', 'rollover_max', 'archivado']);
        $this->membresias->actualizarProducto($producto, $atributos);

        $actor = $request->attributes->get('usuario_tenant');
        $this->auditoria->registrar(
            $actor instanceof Usuario ? $actor : null,
            'producto.actualizado',
            'producto',
            $producto->ulid,
            $antes,
            $producto->only(['nombre', 'precio_minor', 'ilimitado', 'creditos_incluidos', 'vigencia_dias', 'politica_reset', 'unidades_por_ciclo', 'politica_rollover', 'rollover_max', 'archivado']),
        );

        return response()->json(['data' => $this->presentarProducto($producto)]);
    }

    public function vender(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'persona_id' => ['required', 'string'],
            'producto_id' => ['required', 'string'],
            'fecha_inicio' => ['nullable', 'date'],
        ]);

        $persona = PersonaTenant::query()->where('ulid', $validado['persona_id'])->firstOrFail();
        $producto = ProductoTenant::query()->where('ulid', $validado['producto_id'])->firstOrFail();

        $actor = $request->attributes->get('usuario_tenant');
        $acuerdo = $this->membresias->venderProducto(
            $persona,
            $producto,
            $validado['fecha_inicio'] ?? null,
            $actor instanceof Usuario ? $actor : null,
        );
        $derecho = $acuerdo->derechos()->firstOrFail();

        return response()->json([
            'data' => [
                'acuerdo' => $acuerdo->ulid,
                'persona' => $persona->ulid,
                'producto' => $producto->nombre,
                'derecho' => $this->presentarDerecho($derecho),
            ],
        ], 201);
    }

    public function derechos(Request $request): JsonResponse
    {
        // Su historial se consulta aunque esté dada de baja.
        $persona = PersonaTenant::withTrashed()->where('ulid', (string) $request->route('persona'))->firstOrFail();

        $derechos = DerechoTenant::query()
            ->whereHas('acuerdo', fn ($q) => $q->where('persona_id', $persona->getKey()))
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $derechos->map(fn (DerechoTenant $derecho): array => $this->presentarDerecho($derecho))->all(),
        ]);
    }

    public function topUp(Request $request): JsonResponse
    {
        $derecho = DerechoTenant::query()->where('ulid', (string) $request->route('derecho'))->firstOrFail();
        $validado = $request->validate([
            'unidades' => ['required', 'integer', 'min:1'],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ]);

        $unidades = (int) $validado['unidades'];
        $descripcion = is_string($validado['descripcion'] ?? null) ? $validado['descripcion'] : null;

        // Concesion manual de credito = operacion sensible: el actor queda en el propio
        // asiento del ledger (origen/actor) y en la bitacora transversal de auditoria.
        $actor = $request->attributes->get('usuario_tenant');
        $actorUsuario = $actor instanceof Usuario ? $actor : null;
        $this->membresias->agregarTopUp($derecho, $unidades, $descripcion, $actorUsuario);

        $this->auditoria->registrar(
            $actorUsuario,
            'credito.top_up',
            'derecho',
            $derecho->ulid,
            null,
            ['unidades' => $unidades, 'saldo_nuevo' => $this->libro->saldo($derecho->refresh())],
            $descripcion,
        );

        return response()->json(['data' => $this->presentarDerecho($derecho->refresh())], 201);
    }

    /**
     * Resuelve un ulid a la PK interna de un modelo tenant (o null).
     *
     * @param  class-string<ActividadTenant|SucursalTenant>  $modelo
     */
    private function resolverId(string $modelo, ?string $ulid): ?int
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }

        $encontrado = $modelo::query()->where('ulid', $ulid)->first();

        return $encontrado instanceof $modelo ? (int) $encontrado->getKey() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarProducto(ProductoTenant $producto): array
    {
        $producto->loadMissing(['actividad', 'sucursal']);

        return [
            'id' => $producto->ulid,
            'nombre' => $producto->nombre,
            'tipo' => $producto->tipo->value,
            'precio_minor' => $producto->precio_minor,
            'moneda' => $producto->moneda,
            'ilimitado' => $producto->ilimitado,
            'creditos_incluidos' => $producto->creditos_incluidos,
            'vigencia_dias' => $producto->vigencia_dias,
            'archivado' => $producto->archivado,
            'politica_reset' => $producto->politica_reset->value,
            'unidades_por_ciclo' => $producto->unidades_por_ciclo,
            'politica_rollover' => $producto->politica_rollover->value,
            'rollover_max' => $producto->rollover_max,
            'actividad_id' => $producto->actividad?->ulid,
            'actividad' => $producto->actividad?->nombre,
            'sucursal_id' => $producto->sucursal?->ulid,
            'sucursal' => $producto->sucursal?->nombre,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarDerecho(DerechoTenant $derecho): array
    {
        return [
            'id' => $derecho->ulid,
            'ambito' => $derecho->ambito,
            'ilimitado' => $derecho->ilimitado,
            'saldo' => $derecho->ilimitado ? null : $this->libro->saldo($derecho),
            'disponible' => $derecho->ilimitado ? null : $this->libro->disponible($derecho),
        ];
    }
}
