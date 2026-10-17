<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EliminacionesTenant;
use App\Modules\Tenancy\Models\ExcepcionHorarioTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Excepciones de horario del estudio (R5): fechas (feriados/cierres) en las que la
 * generacion recurrente NO crea sesiones. Idempotente por fecha. Opera SIEMPRE sobre
 * la BD del estudio resuelto.
 */
class ExcepcionesHorarioTenantController
{
    /**
     * Los días cerrados, en orden. Con `desde` (AAAA-MM-DD), solo de ese día en
     * adelante: los pasados se juntan año con año y la pantalla no los usa.
     */
    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate(['desde' => ['nullable', 'date_format:Y-m-d']]);
        $excepciones = ExcepcionHorarioTenant::query()
            ->when($validado['desde'] ?? null, fn ($q, string $desde) => $q->whereDate('fecha', '>=', $desde))
            ->orderBy('fecha')
            ->get();

        return response()->json([
            'data' => $excepciones->map(fn (ExcepcionHorarioTenant $e): array => [
                'id' => $e->ulid,
                'fecha' => $e->fecha->toDateString(),
                'motivo' => $e->motivo,
            ])->all(),
        ]);
    }

    public function crear(Request $request, EliminacionesTenant $eliminaciones): JsonResponse
    {
        $validado = $request->validate([
            'fecha' => ['required', 'date'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        // Un día cerrado por fecha (la columna guarda también la hora: se compara el
        // día). Si ya existe se actualiza; si estaba eliminado, se restaura.
        $fecha = Carbon::parse((string) $validado['fecha'])->toDateString();
        $excepcion = ExcepcionHorarioTenant::withTrashed()->whereDate('fecha', $fecha)->first();
        if ($excepcion instanceof ExcepcionHorarioTenant) {
            $excepcion->update(['motivo' => $validado['motivo'] ?? null]);
            if ($excepcion->trashed()) {
                $excepcion->restore();
                $eliminaciones->restaurado($excepcion, 'excepcion_horario', $excepcion->only($excepcion->getFillable()));
            }
        } else {
            $excepcion = ExcepcionHorarioTenant::query()->create(['fecha' => $fecha, 'motivo' => $validado['motivo'] ?? null]);
        }

        return response()->json(['data' => [
            'id' => $excepcion->ulid,
            'fecha' => $excepcion->fecha->toDateString(),
            'motivo' => $excepcion->motivo,
        ]], 201);
    }

    public function eliminar(Request $request, EliminacionesTenant $eliminaciones): JsonResponse
    {
        $excepcion = ExcepcionHorarioTenant::query()->where('ulid', (string) $request->route('excepcion'))->firstOrFail();
        // Baja lógica: deja de usarse; queda en la bitácora qué era y quién lo eliminó.
        $eliminaciones->eliminar($excepcion, 'excepcion_horario', $excepcion->only(['fecha', 'motivo']));

        return response()->json(status: 204);
    }
}
