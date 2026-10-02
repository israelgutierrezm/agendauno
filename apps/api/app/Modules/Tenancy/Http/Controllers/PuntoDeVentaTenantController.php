<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CorregirVentaPosTenant;
use App\Modules\Tenancy\Application\PuntoDeVentaTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VentaPosTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Punto de venta minorista del estudio, tenant-local (R21): cobra un ticket de caja
 * (descuenta stock) y lista las ventas. Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class PuntoDeVentaTenantController
{
    public function __construct(
        private readonly PuntoDeVentaTenant $pos,
        private readonly ResolverAccesoTenant $acceso,
        private readonly CorregirVentaPosTenant $corregir,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Alcance por sucursal (R19): el staff acotado solo ve las ventas de SUS sedes.
        $actor = $this->actor($request);
        $permitidas = $actor !== null ? $this->acceso->sucursalesPermitidas($actor) : null;

        $ventas = VentaPosTenant::query()
            ->with(['sucursal', 'lineas.articulo'])
            ->when($permitidas !== null, fn ($q) => $q->whereIn('sucursal_id', $permitidas))
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $ventas->map(fn (VentaPosTenant $v): array => $this->presentar($v))->all(),
        ]);
    }

    public function vender(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'sucursal_id' => ['required', 'string'],
            'metodo_pago' => ['nullable', 'string', 'max:40'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.articulo_id' => ['required', 'string'],
            'items.*.cantidad' => ['required', 'integer', 'min:1'],
        ]);

        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();

        // Alcance por sucursal (R19): un acotado no vende en una sede ajena.
        $actor = $this->actor($request);
        abort_unless(
            $actor === null || $this->acceso->permiteSucursal($actor, (int) $sucursal->id),
            403,
            'No puedes vender en una sucursal que no te corresponde.',
        );

        $items = [];
        foreach ($validado['items'] as $item) {
            $articulo = ArticuloTenant::query()->where('ulid', $item['articulo_id'])->firstOrFail();
            $items[] = ['articulo' => $articulo, 'cantidad' => (int) $item['cantidad']];
        }

        $venta = $this->pos->vender($sucursal, $items, $validado['metodo_pago'] ?? 'efectivo', $this->actor($request));

        return response()->json(['data' => $this->presentar($venta->load(['sucursal', 'lineas.articulo']))], 201);
    }

    /**
     * Corrige la forma de pago de una venta registrada con la equivocada (ADR 0089).
     */
    public function corregirMetodo(Request $request): JsonResponse
    {
        $venta = $this->ventaDeMiSede($request);
        $validado = $request->validate([
            'metodo' => ['required', Rule::in(CorregirVentaPosTenant::METODOS)],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);
        $venta = $this->corregir->corregirMetodo($venta, $validado['metodo'], $this->actor($request), $validado['motivo'] ?? null);

        return response()->json(['data' => $this->presentar($venta->load(['sucursal', 'lineas.articulo']))]);
    }

    /**
     * Anula una venta registrada por error: no cuenta en el corte y lo vendido
     * regresa al inventario (ADR 0089).
     */
    public function anular(Request $request): JsonResponse
    {
        $venta = $this->ventaDeMiSede($request);
        $validado = $request->validate(['motivo' => ['required', 'string', 'max:255']]);
        $venta = $this->corregir->anular($venta, $validado['motivo'], $this->actor($request));

        return response()->json(['data' => $this->presentar($venta->load(['sucursal', 'lineas.articulo']))]);
    }

    /** La venta, si es de una sede que le corresponde a quien la toca (R19). */
    private function ventaDeMiSede(Request $request): VentaPosTenant
    {
        $venta = VentaPosTenant::query()->where('ulid', (string) $request->route('venta'))->firstOrFail();
        $actor = $this->actor($request);
        abort_unless($actor === null || $this->acceso->permiteSucursal($actor, (int) $venta->sucursal_id), 403);

        return $venta;
    }

    private function actor(Request $request): ?Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');

        return $usuario instanceof Usuario ? $usuario : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(VentaPosTenant $venta): array
    {
        return [
            'id' => $venta->ulid,
            'sucursal' => $venta->sucursal?->nombre,
            'total_minor' => $venta->total_minor,
            'moneda' => $venta->moneda,
            'metodo_pago' => $venta->metodo_pago,
            'creado_en' => $venta->created_at?->toIso8601String(),
            // Corregir la forma de pago o anularla (ADR 0089).
            'anulada_en' => $venta->anulada_en?->toIso8601String(),
            'motivo_anulacion' => $venta->motivo_anulacion,
            'corregible' => $this->corregir->impedimentoMetodo($venta) === null,
            'anulable' => $this->corregir->impedimentoAnular($venta) === null,
            'lineas' => $venta->lineas->map(fn ($l): array => [
                'articulo' => $l->articulo?->nombre,
                'cantidad' => $l->cantidad,
                'subtotal_minor' => $l->subtotal_minor,
            ])->all(),
        ];
    }
}
