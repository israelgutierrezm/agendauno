<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\GestionarPromocionesTenant;
use App\Modules\Tenancy\Models\PromocionTenant;
use App\Modules\Tenancy\Ordenes\TipoPromocion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Promociones / cupones del estudio, tenant-local (R22): CRUD de códigos de descuento y
 * validación de un código contra un subtotal (para el checkout). Opera SIEMPRE sobre la
 * BD del estudio resuelto.
 */
class PromocionesTenantController
{
    public function __construct(private readonly GestionarPromocionesTenant $promociones) {}

    public function index(): JsonResponse
    {
        $promos = PromocionTenant::query()->orderByDesc('id')->get();

        return response()->json([
            'data' => $promos->map(fn (PromocionTenant $p): array => $this->presentar($p))->all(),
            'tipos' => array_map(fn (TipoPromocion $t): string => $t->value, TipoPromocion::cases()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->normalizarDatos($request);

        if (PromocionTenant::query()->where('codigo', mb_strtoupper(trim((string) $datos['codigo'])))->exists()) {
            throw ValidationException::withMessages(['codigo' => ['Ya existe una promoción con ese código.']]);
        }

        $promocion = $this->promociones->crear($datos);

        return response()->json(['data' => $this->presentar($promocion)], 201);
    }

    public function actualizar(Request $request): JsonResponse
    {
        $promocion = $this->resolver($request);
        $datos = $this->normalizarDatos($request);

        $duplicada = PromocionTenant::query()
            ->where('codigo', mb_strtoupper(trim((string) $datos['codigo'])))
            ->whereKeyNot($promocion->getKey())
            ->exists();
        if ($duplicada) {
            throw ValidationException::withMessages(['codigo' => ['Ya existe una promoción con ese código.']]);
        }

        $this->promociones->actualizar($promocion, $datos);

        return response()->json(['data' => $this->presentar($promocion->refresh())]);
    }

    public function eliminar(Request $request): JsonResponse
    {
        $promocion = $this->resolver($request);
        $promocion->delete();

        return response()->json(['data' => ['id' => $promocion->ulid, 'eliminada' => true]]);
    }

    /**
     * Valida un código contra un subtotal (sin consumir uso) para mostrar el descuento
     * en el checkout. Lanza PROMO_INVALID si no aplica.
     */
    public function validar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'codigo' => ['required', 'string', 'max:64'],
            'subtotal_minor' => ['required', 'integer', 'min:0'],
        ]);

        $resultado = $this->promociones->previsualizar($validado['codigo'], (int) $validado['subtotal_minor']);

        return response()->json(['data' => [
            'codigo' => $resultado['promocion']->codigo,
            'descuento_minor' => $resultado['descuento'],
            'total_minor' => (int) $validado['subtotal_minor'] - $resultado['descuento'],
        ]]);
    }

    private function resolver(Request $request): PromocionTenant
    {
        return PromocionTenant::query()->where('ulid', (string) $request->route('promocion'))->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(PromocionTenant $promocion): array
    {
        return [
            'id' => $promocion->ulid,
            'codigo' => $promocion->codigo,
            'descripcion' => $promocion->descripcion,
            'tipo' => $promocion->tipo->value,
            'valor' => $promocion->valor,
            'monto_minimo_minor' => $promocion->monto_minimo_minor,
            'usos_maximos' => $promocion->usos_maximos,
            'usos' => $promocion->usos,
            'vence_en' => $promocion->vence_en?->toDateString(),
            'activa' => $promocion->activa,
            'vigente' => $promocion->vigente(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reglas(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:64'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'tipo' => ['required', Rule::enum(TipoPromocion::class)],
            'valor' => ['required', 'integer', 'min:1'],
            'monto_minimo_minor' => ['nullable', 'integer', 'min:0'],
            'usos_maximos' => ['nullable', 'integer', 'min:1'],
            'vence_en' => ['nullable', 'date'],
            'activa' => ['boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizarDatos(Request $request): array
    {
        $v = $request->validate($this->reglas());

        return [
            'codigo' => $v['codigo'],
            'descripcion' => $v['descripcion'] ?? null,
            'tipo' => $v['tipo'],
            'valor' => (int) $v['valor'],
            'monto_minimo_minor' => isset($v['monto_minimo_minor']) ? (int) $v['monto_minimo_minor'] : null,
            'usos_maximos' => isset($v['usos_maximos']) ? (int) $v['usos_maximos'] : null,
            'vence_en' => $v['vence_en'] ?? null,
            'activa' => (bool) ($v['activa'] ?? true),
        ];
    }
}
