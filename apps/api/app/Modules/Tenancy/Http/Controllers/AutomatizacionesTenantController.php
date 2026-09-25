<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EliminacionesTenant;
use App\Modules\Tenancy\Automatizacion\AccionAutomatizacion;
use App\Modules\Tenancy\Automatizacion\EventoAutomatizacion;
use App\Modules\Tenancy\Models\ReglaAutomatizacionTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reglas del motor de automatización, tenant-local (R16): CRUD de reglas
 * trigger→condición→retraso→acción (crear tarea). Opera SIEMPRE sobre la BD del
 * estudio resuelto.
 */
class AutomatizacionesTenantController
{
    public function index(): JsonResponse
    {
        $reglas = ReglaAutomatizacionTenant::query()->orderByDesc('id')->get();

        return response()->json([
            'data' => $reglas->map(fn (ReglaAutomatizacionTenant $r): array => $this->presentar($r))->all(),
            'catalogo' => [
                'eventos' => array_map(fn (EventoAutomatizacion $e): string => $e->value, EventoAutomatizacion::cases()),
                'acciones' => array_map(fn (AccionAutomatizacion $a): string => $a->value, AccionAutomatizacion::cases()),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request);

        $regla = ReglaAutomatizacionTenant::query()->create($datos);

        return response()->json(['data' => $this->presentar($regla)], 201);
    }

    public function actualizar(Request $request): JsonResponse
    {
        $regla = $this->resolver($request);
        $datos = $this->validar($request);

        $regla->update($datos);

        return response()->json(['data' => $this->presentar($regla->refresh())]);
    }

    public function eliminar(Request $request, EliminacionesTenant $eliminaciones): JsonResponse
    {
        $regla = $this->resolver($request);
        // Baja lógica: deja de usarse; queda en la bitácora qué era y quién lo eliminó.
        $eliminaciones->eliminar($regla, 'automatizacion', $regla->only(['nombre', 'evento', 'accion']));

        return response()->json(['data' => ['id' => $regla->ulid, 'eliminada' => true]]);
    }

    private function resolver(Request $request): ReglaAutomatizacionTenant
    {
        return ReglaAutomatizacionTenant::query()->where('ulid', (string) $request->route('regla'))->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request): array
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'evento' => ['required', Rule::enum(EventoAutomatizacion::class)],
            'condiciones' => ['nullable', 'array'],
            'titulo_plantilla' => ['required', 'string', 'max:255'],
            'detalle_plantilla' => ['nullable', 'string', 'max:2000'],
            'delay_minutos' => ['nullable', 'integer', 'min:0', 'max:525600'],
            'activa' => ['boolean'],
        ]);

        return [
            'nombre' => $validado['nombre'],
            'evento' => $validado['evento'],
            'condiciones' => $this->limpiarCondiciones($validado['condiciones'] ?? null),
            'accion' => AccionAutomatizacion::CrearTarea->value,
            'titulo_plantilla' => $validado['titulo_plantilla'],
            'detalle_plantilla' => $validado['detalle_plantilla'] ?? null,
            'delay_minutos' => (int) ($validado['delay_minutos'] ?? 0),
            'activa' => (bool) ($validado['activa'] ?? true),
        ];
    }

    /**
     * Normaliza las condiciones a un mapa {campo: valor} de solo texto (descarta claves
     * o valores vacíos).
     *
     * @param  array<string, mixed>|null  $condiciones
     * @return array<string, string>
     */
    private function limpiarCondiciones(?array $condiciones): array
    {
        if ($condiciones === null) {
            return [];
        }

        $limpio = [];
        foreach ($condiciones as $campo => $valor) {
            $campo = trim((string) $campo);
            if ($campo === '' || $valor === null || $valor === '') {
                continue;
            }
            $limpio[$campo] = (string) $valor;
        }

        return $limpio;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(ReglaAutomatizacionTenant $regla): array
    {
        return [
            'id' => $regla->ulid,
            'nombre' => $regla->nombre,
            'evento' => $regla->evento,
            'condiciones' => $regla->condiciones ?? [],
            'accion' => $regla->accion->value,
            'titulo_plantilla' => $regla->titulo_plantilla,
            'detalle_plantilla' => $regla->detalle_plantilla,
            'delay_minutos' => $regla->delay_minutos,
            'activa' => $regla->activa,
        ];
    }
}
