<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcancePersonaTenant;
use App\Modules\Tenancy\Application\CobrarOrdenTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\OrdenesTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Modules\Tenancy\Pagos\PartesCobro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ordenes del estudio (data plane del tenant): crea ordenes pendientes con precio
 * congelado y las liquida manualmente (ventanilla), haciendo el fulfillment
 * (concesion de derechos). El cobro con pasarela real es un modulo posterior.
 * Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class OrdenesTenantController
{
    private const POR_PAGINA = 25;

    // `tarjeta`: cobrada con la terminal del negocio (no por la pasarela en línea).
    private const METODOS = ['efectivo', 'transferencia', 'tarjeta', 'ventanilla', 'manual'];

    public function __construct(
        private readonly OrdenesTenant $ordenes,
        private readonly CobrarOrdenTenant $cobrar,
        private readonly ResolverAccesoTenant $acceso,
        private readonly RegistrarAuditoria $auditoria,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    /**
     * Las órdenes por páginas, de la más reciente a la más antigua: se buscan por el
     * comprador y se acotan por estado y por las fechas en que se crearon (días del
     * negocio). `meta.resumen` es lo de hoy en el negocio (ventas pagadas hoy, lo
     * vendido hoy por moneda y lo que queda por cobrar), sin los filtros: no cambia al
     * buscar.
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::enum(EstadoOrden::class)],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (isset($filtros['desde'], $filtros['hasta']) && $filtros['hasta'] < $filtros['desde']) {
            throw ValidationException::withMessages(['hasta' => ['La fecha final no puede ser antes que la inicial.']]);
        }

        // Alcance por sucursal (R19): el staff acotado solo ve las órdenes de SUS sedes.
        $actor = $this->actor($request);
        $permitidas = $actor !== null ? $this->acceso->sucursalesPermitidas($actor) : null;
        $deSusSedes = fn (): Builder => OrdenTenant::query()
            ->when($permitidas !== null, fn ($q) => $q->whereIn('sucursal_id', $permitidas));

        $q = trim((string) ($filtros['q'] ?? ''));
        $pagina = $deSusSedes()
            ->with(['persona', 'lineas.producto', 'lineas.beneficiario'])
            ->when($q !== '', fn ($c) => $c->whereHas('persona', fn ($p) => $p->buscar($q)))
            ->when(isset($filtros['estado']), fn ($c) => $c->where('estado', $filtros['estado']))
            ->when(isset($filtros['desde']), fn ($c) => $c->where('created_at', '>=', $this->fechas->inicioDelDia((string) $filtros['desde'])))
            ->when(isset($filtros['hasta']), fn ($c) => $c->where('created_at', '<=', $this->fechas->finDelDia((string) $filtros['hasta'])))
            ->orderByDesc('id')
            ->paginate((int) ($filtros['per_page'] ?? self::POR_PAGINA), ['*'], 'page', (int) ($filtros['page'] ?? 1));

        $hoy = $this->fechas->hoy();
        $pagadasHoy = fn (): Builder => $deSusSedes()
            ->where('estado', EstadoOrden::Pagada->value)
            ->whereBetween('pagada_en', [$this->fechas->inicioDelDia($hoy), $this->fechas->finDelDia($hoy)]);

        return response()->json([
            'data' => collect($pagina->items())->map(fn (OrdenTenant $orden): array => $this->presentar($orden))->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'resumen' => [
                    'ventas_hoy' => $pagadasHoy()->count(),
                    'vendido_hoy' => $pagadasHoy()
                        ->selectRaw('moneda, SUM(total_minor) as total_minor')
                        ->groupBy('moneda')
                        ->orderBy('moneda')
                        ->toBase()
                        ->get()
                        ->map(fn (object $fila): array => ['moneda' => (string) $fila->moneda, 'total_minor' => (int) $fila->total_minor])
                        ->all(),
                    'por_cobrar' => $deSusSedes()->where('estado', EstadoOrden::Pendiente->value)->count(),
                ],
            ],
        ]);
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }

    public function crear(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'comprador_id' => ['required', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'string'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],
            'items.*.beneficiario_id' => ['nullable', 'string'],
            'codigo_promo' => ['nullable', 'string', 'max:64'],
            // Vender sin la inscripción que tocaría (B04): solo con `cuotas.exentar`.
            'exentar_inscripcion' => ['sometimes', 'boolean'],
            'motivo_exencion' => ['nullable', 'required_if:exentar_inscripcion,true', 'string', 'max:300'],
            // El cliente aceptó las condiciones de un plan con permanencia (B04).
            'acepta_condiciones' => ['sometimes', 'boolean'],
        ]);

        $comprador = PersonaTenant::query()->where('ulid', $validado['comprador_id'])->firstOrFail();

        // Alcance por sucursal (R19): un acotado solo crea órdenes para alumnos de SU
        // sede; la orden se atribuye a la sede del comprador (o a la del vendedor acotado).
        $actor = $this->actor($request);
        $compradorSucursal = $comprador->sucursal_id !== null ? (int) $comprador->sucursal_id : null;
        abort_unless(
            $actor === null || $this->acceso->permiteSucursal($actor, $compradorSucursal),
            403,
            'No puedes crear órdenes para un alumno de otra sucursal.',
        );
        $permitidas = $actor !== null ? $this->acceso->sucursalesPermitidas($actor) : null;
        $sucursalId = $compradorSucursal ?? ($permitidas[0] ?? null);

        $items = [];
        foreach ($validado['items'] as $item) {
            $producto = ProductoTenant::query()->where('ulid', $item['producto_id'])->firstOrFail();
            $beneficiario = isset($item['beneficiario_id']) && $item['beneficiario_id'] !== ''
                ? PersonaTenant::query()->where('ulid', $item['beneficiario_id'])->firstOrFail()
                : null;

            $items[] = [
                'producto' => $producto,
                'cantidad' => (int) $item['cantidad'],
                'beneficiario' => $beneficiario,
            ];
        }

        $exentar = (bool) ($validado['exentar_inscripcion'] ?? false);
        abort_if($exentar && ! ($actor instanceof Usuario && $actor->puede('cuotas.exentar')), 403, 'No puedes exentar la inscripción.');

        $orden = $this->ordenes->crear(
            $comprador,
            $items,
            $validado['codigo_promo'] ?? null,
            $sucursalId,
            exencionInscripcion: $exentar ? ['actor' => $actor, 'motivo' => trim((string) ($validado['motivo_exencion'] ?? ''))] : null,
            aceptacion: ($validado['acepta_condiciones'] ?? false) === true
                ? ['via' => 'negocio', 'usuario' => $actor, 'ip' => $request->ip()]
                : null,
        );

        return response()->json(['data' => $this->presentar($orden->refresh())], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $orden = OrdenTenant::query()->where('ulid', (string) $request->route('orden'))->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirOrden($request, $orden);

        return response()->json(['data' => $this->presentar($orden)]);
    }

    public function cobrar(Request $request): JsonResponse
    {
        $orden = OrdenTenant::query()->where('ulid', (string) $request->route('orden'))->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirOrden($request, $orden);

        $validado = $request->validate([
            'proveedor' => ['required', 'string'],
            'metodo' => ['nullable', Rule::enum(MetodoPago::class)],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'sucursal_id' => ['nullable', 'string'],
        ]);

        $metodo = isset($validado['metodo']) ? MetodoPago::from($validado['metodo']) : null;
        $key = ($validado['idempotency_key'] ?? '') !== '' ? $validado['idempotency_key'] : null;

        $actor = $request->attributes->get('usuario_tenant');
        $actor = $actor instanceof Usuario ? $actor : null;
        $pago = $this->cobrar->ejecutar($orden, $validado['proveedor'], $metodo, $key, '/ventas', $actor, $this->sucursalDeCaja($validado['sucursal_id'] ?? null, $actor));
        if ($pago->estado === EstadoPago::Aprobado && $pago->wasRecentlyCreated) {
            $this->auditoria->registrar($actor, 'pago.registrado', 'pago', (string) $pago->ulid, null, [
                'orden' => (string) $orden->ulid,
                'monto_minor' => $pago->monto_minor,
                'moneda' => $pago->moneda,
                'metodo' => $pago->metodo->value ?? $pago->proveedor,
            ]);
        }

        return response()->json(['data' => [
            'pago' => $pago->ulid,
            'proveedor' => $pago->proveedor,
            'estado' => $pago->estado->value,
            'checkout' => $pago->checkout,
            'orden' => $this->presentar($orden->refresh()),
        ]], 201);
    }

    public function liquidar(Request $request): JsonResponse
    {
        $orden = OrdenTenant::query()->where('ulid', (string) $request->route('orden'))->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirOrden($request, $orden);

        $validado = $request->validate([
            'metodo' => ['required', Rule::in(self::METODOS)],
            'referencia' => ['nullable', 'string', 'max:255'],
            // Dónde se cobra (la sucursal de la barra): su caja lo registra (ADR 0175).
            'sucursal_id' => ['nullable', 'string'],
            // Con varias formas de pago (ADR 0177): cuánto con cada una; suman el total.
            ...PartesCobro::reglas(),
        ]);
        $partes = PartesCobro::desde($validado['partes'] ?? null);

        $actor = $request->attributes->get('usuario_tenant');
        $actor = $actor instanceof Usuario ? $actor : null;
        $pago = $this->ordenes->liquidar($orden, $validado['metodo'], $validado['referencia'] ?? null, $actor, $this->sucursalDeCaja($validado['sucursal_id'] ?? null, $actor), $partes);

        // Cobro en caja: queda quién lo registró (el pago lo guarda; aquí, la bitácora).
        if ($pago !== null) {
            $this->auditoria->registrar($actor, 'pago.registrado', 'pago', (string) $pago->ulid, null, [
                'orden' => (string) $orden->ulid,
                'monto_minor' => $pago->monto_minor,
                'moneda' => $pago->moneda,
                'metodo' => $partes !== null ? PartesCobro::MIXTO : $validado['metodo'],
                'partes' => $partes,
                'referencia' => $validado['referencia'] ?? null,
            ]);
        }

        return response()->json(['data' => $this->presentar($orden->refresh())]);
    }

    /** La sede donde se cobra en el mostrador, si es una de quien cobra (su caja; ADR 0175). */
    private function sucursalDeCaja(?string $ulid, ?Usuario $actor): ?int
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }
        $id = SucursalTenant::query()->where('ulid', $ulid)->value('id');
        if ($id === null || ($actor !== null && ! $this->acceso->permiteSucursal($actor, (int) $id))) {
            throw ValidationException::withMessages(['sucursal_id' => ['No puedes cobrar en esa sucursal.']]);
        }

        return (int) $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(OrdenTenant $orden): array
    {
        $orden->loadMissing(['persona', 'lineas.producto', 'lineas.beneficiario']);

        return [
            'id' => $orden->ulid,
            'comprador' => $orden->persona?->nombre,
            'estado' => $orden->estado->value,
            'total_minor' => $orden->total_minor,
            'descuento_minor' => (int) ($orden->descuento_minor ?? 0),
            'moneda' => $orden->moneda,
            'metodo_pago' => $orden->metodo_pago,
            'referencia_pago' => $orden->referencia_pago,
            'pagada_en' => $orden->pagada_en?->toIso8601String(),
            'lineas' => $orden->lineas->map(fn (LineaOrdenTenant $linea): array => [
                'id' => $linea->ulid,
                'producto' => $linea->nombre(),
                // Una cuota (inscripción, anualidad, penalización; B04) no es un producto.
                'concepto' => $linea->concepto?->value,
                'beneficiario' => $linea->beneficiario?->nombre,
                'cantidad' => $linea->cantidad,
                'precio_unitario_minor' => $linea->precio_unitario_minor,
                'subtotal_minor' => $linea->subtotal_minor,
            ])->all(),
        ];
    }
}
