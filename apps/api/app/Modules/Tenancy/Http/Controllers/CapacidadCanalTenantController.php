<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EliminacionesTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\ReglaCapacidadCanalTenant;
use App\Modules\Tenancy\Reservas\CanalReserva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reglas de capacidad por canal de una oferta, tenant-local (R20): apartan cupos para
 * un marketplace/agregador con liberación progresiva. Una regla por (oferta, canal).
 * Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class CapacidadCanalTenantController
{
    public function index(Request $request): JsonResponse
    {
        $oferta = $this->resolverOferta($request);

        $reglas = ReglaCapacidadCanalTenant::query()
            ->where('oferta_id', $oferta->getKey())
            ->orderBy('canal')
            ->get();

        return response()->json([
            'data' => $reglas->map(fn (ReglaCapacidadCanalTenant $r): array => $this->presentar($r))->all(),
            'canales' => array_map(fn (CanalReserva $c): string => $c->value, CanalReserva::cases()),
        ]);
    }

    public function guardar(Request $request, EliminacionesTenant $eliminaciones): JsonResponse
    {
        $oferta = $this->resolverOferta($request);

        $validado = $request->validate([
            'canal' => ['required', Rule::enum(CanalReserva::class)],
            'cupos' => ['required', 'integer', 'min:0', 'max:100000'],
            'liberar_horas_antes' => ['nullable', 'integer', 'min:0', 'max:8760'],
            'activa' => ['boolean'],
        ]);

        $regla = ReglaCapacidadCanalTenant::withTrashed()->updateOrCreate(
            ['oferta_id' => $oferta->getKey(), 'canal' => $validado['canal']],
            [
                'cupos' => (int) $validado['cupos'],
                'liberar_horas_antes' => (int) ($validado['liberar_horas_antes'] ?? 0),
                'activa' => (bool) ($validado['activa'] ?? true),
            ],
        );
        // Esa clave estaba eliminada: se restaura con los datos nuevos.
        if ($regla->trashed()) {
            $regla->restore();
            $eliminaciones->restaurado($regla, 'capacidad', $regla->only($regla->getFillable()));
        }

        return response()->json(['data' => $this->presentar($regla)], 201);
    }

    public function eliminar(Request $request, EliminacionesTenant $eliminaciones): JsonResponse
    {
        $regla = ReglaCapacidadCanalTenant::query()->where('ulid', (string) $request->route('regla'))->firstOrFail();
        // Baja lógica: deja de usarse; queda en la bitácora qué era y quién lo eliminó.
        $eliminaciones->eliminar($regla, 'capacidad', $regla->only(['canal', 'cupos']));

        return response()->json(['data' => ['id' => $regla->ulid, 'eliminada' => true]]);
    }

    private function resolverOferta(Request $request): OfertaTenant
    {
        return OfertaTenant::query()->where('ulid', (string) $request->route('oferta'))->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(ReglaCapacidadCanalTenant $regla): array
    {
        return [
            'id' => $regla->ulid,
            'canal' => $regla->canal->value,
            'cupos' => $regla->cupos,
            'liberar_horas_antes' => $regla->liberar_horas_antes,
            'activa' => $regla->activa,
        ];
    }
}
