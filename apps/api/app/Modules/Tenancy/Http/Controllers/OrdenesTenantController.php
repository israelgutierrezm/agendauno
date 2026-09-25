<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CobrarOrdenTenant;
use App\Modules\Tenancy\Application\OrdenesTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\MetodoPago;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ordenes del estudio (data plane del tenant): crea ordenes pendientes con precio
 * congelado y las liquida manualmente (ventanilla), haciendo el fulfillment
 * (concesion de derechos). El cobro con pasarela real es un modulo posterior.
 * Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class OrdenesTenantController
{
    private const LIMITE = 200;

    private const METODOS = ['efectivo', 'transferencia', 'ventanilla', 'manual'];

    public function __construct(
        private readonly OrdenesTenant $ordenes,
        private readonly CobrarOrdenTenant $cobrar,
        private readonly ResolverAccesoTenant $acceso,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Alcance por sucursal (R19): el staff acotado solo ve las órdenes de SUS sedes.
        $actor = $this->actor($request);
        $permitidas = $actor !== null ? $this->acceso->sucursalesPermitidas($actor) : null;

        $ordenes = OrdenTenant::query()->with(['persona', 'lineas.producto', 'lineas.beneficiario'])
            ->when($permitidas !== null, fn ($q) => $q->whereIn('sucursal_id', $permitidas))
            ->orderByDesc('id')->limit(self::LIMITE)->get();

        return response()->json([
            'data' => $ordenes->map(fn (OrdenTenant $orden): array => $this->presentar($orden))->all(),
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

        $orden = $this->ordenes->crear($comprador, $items, $validado['codigo_promo'] ?? null, $sucursalId);

        return response()->json(['data' => $this->presentar($orden->refresh())], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $orden = OrdenTenant::query()->where('ulid', (string) $request->route('orden'))->firstOrFail();

        return response()->json(['data' => $this->presentar($orden)]);
    }

    public function cobrar(Request $request): JsonResponse
    {
        $orden = OrdenTenant::query()->where('ulid', (string) $request->route('orden'))->firstOrFail();

        $validado = $request->validate([
            'proveedor' => ['required', 'string'],
            'metodo' => ['nullable', Rule::enum(MetodoPago::class)],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $metodo = isset($validado['metodo']) ? MetodoPago::from($validado['metodo']) : null;
        $key = ($validado['idempotency_key'] ?? '') !== '' ? $validado['idempotency_key'] : null;

        $actor = $request->attributes->get('usuario_tenant');
        $actor = $actor instanceof Usuario ? $actor : null;
        $pago = $this->cobrar->ejecutar($orden, $validado['proveedor'], $metodo, $key, '/ventas', $actor);
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

        $validado = $request->validate([
            'metodo' => ['required', Rule::in(self::METODOS)],
            'referencia' => ['nullable', 'string', 'max:255'],
        ]);

        $actor = $request->attributes->get('usuario_tenant');
        $actor = $actor instanceof Usuario ? $actor : null;
        $pago = $this->ordenes->liquidar($orden, $validado['metodo'], $validado['referencia'] ?? null, $actor);

        // Cobro en caja: queda quién lo registró (el pago lo guarda; aquí, la bitácora).
        if ($pago !== null) {
            $this->auditoria->registrar($actor, 'pago.registrado', 'pago', (string) $pago->ulid, null, [
                'orden' => (string) $orden->ulid,
                'monto_minor' => $pago->monto_minor,
                'moneda' => $pago->moneda,
                'metodo' => $validado['metodo'],
                'referencia' => $validado['referencia'] ?? null,
            ]);
        }

        return response()->json(['data' => $this->presentar($orden->refresh())]);
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
                'producto' => $linea->producto?->nombre,
                'beneficiario' => $linea->beneficiario?->nombre,
                'cantidad' => $linea->cantidad,
                'precio_unitario_minor' => $linea->precio_unitario_minor,
                'subtotal_minor' => $linea->subtotal_minor,
            ])->all(),
        ];
    }
}
