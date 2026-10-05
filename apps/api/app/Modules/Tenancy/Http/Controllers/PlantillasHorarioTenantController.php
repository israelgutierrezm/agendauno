<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CambiarSerieTenant;
use App\Modules\Tenancy\Application\EliminacionesTenant;
use App\Modules\Tenancy\Application\GenerarAgendaTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Plantillas de horario recurrente del estudio (R5): definen la agenda que se
 * materializa en sesiones. `generar` produce las sesiones de un rango bajo demanda
 * (ademas del comando diario). Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class PlantillasHorarioTenantController
{
    public function __construct(private readonly GenerarAgendaTenant $generar) {}

    public function index(): JsonResponse
    {
        $plantillas = PlantillaHorarioTenant::query()->with(['oferta.actividad', 'sucursal', 'instructor'])->orderByDesc('id')->get();

        return response()->json([
            'data' => $plantillas->map(fn (PlantillaHorarioTenant $p): array => $this->presentar($p))->all(),
        ]);
    }

    public function crear(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'oferta_id' => ['required', 'string'],
            'sucursal_id' => ['required', 'string'],
            'instructor_id' => ['nullable', 'string'],
            'recurso_id' => ['nullable', 'string'],
            'dias_semana' => ['required', 'array', 'min:1'],
            'dias_semana.*' => ['integer', 'min:1', 'max:7'],
            'hora_local' => ['required', 'date_format:H:i'],
            'duracion_minutos' => ['required', 'integer', 'min:1', 'max:1440'],
            'capacidad' => ['nullable', 'integer', 'min:1'],
            'vigente_desde' => ['required', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
            'activo' => ['boolean'],
        ]);

        $oferta = OfertaTenant::query()->where('ulid', $validado['oferta_id'])->firstOrFail();
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();

        $plantilla = PlantillaHorarioTenant::query()->create([
            'oferta_id' => $oferta->getKey(),
            'sucursal_id' => $sucursal->getKey(),
            'instructor_id' => $this->resolverInstructor($validado['instructor_id'] ?? null),
            'recurso_id' => $this->resolverRecurso($validado['recurso_id'] ?? null),
            'dias_semana' => array_values(array_unique(array_map('intval', $validado['dias_semana']))),
            'hora_local' => $validado['hora_local'],
            'duracion_minutos' => (int) $validado['duracion_minutos'],
            'capacidad' => isset($validado['capacidad']) ? (int) $validado['capacidad'] : null,
            'vigente_desde' => $validado['vigente_desde'],
            'vigente_hasta' => $validado['vigente_hasta'] ?? null,
            'activo' => (bool) ($validado['activo'] ?? true),
        ]);

        return response()->json(['data' => $this->presentar($plantilla->load(['oferta.actividad', 'sucursal', 'instructor']))], 201);
    }

    public function eliminar(Request $request, EliminacionesTenant $eliminaciones): JsonResponse
    {
        $plantilla = PlantillaHorarioTenant::query()->where('ulid', (string) $request->route('plantilla'))->firstOrFail();
        // Baja lógica: deja de usarse; queda en la bitácora qué era y quién lo eliminó.
        $eliminaciones->eliminar($plantilla, 'plantilla_horario', $plantilla->only(['dias_semana', 'hora_local', 'duracion_minutos', 'vigente_desde', 'vigente_hasta']));

        return response()->json(status: 204);
    }

    public function generar(Request $request): JsonResponse
    {
        $plantilla = PlantillaHorarioTenant::query()->where('ulid', (string) $request->route('plantilla'))->firstOrFail();

        $validado = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ]);

        $resultado = $this->generar->ejecutar($plantilla, $validado['desde'], $validado['hasta']);

        // Las fechas que no se pudieron generar, con su motivo (no se omiten en silencio).
        return response()->json(['data' => ['creadas' => $resultado->creadas, 'omitidas' => $resultado->omitidas]], 201);
    }

    /**
     * "Esta y las siguientes" (2.5): cambia días (ADR 0045), hora, duración,
     * profesional o sala desde `desde`. Con `previsualizar` no guarda nada y dice lo mismo que pasaría.
     */
    public function cambiar(Request $request, CambiarSerieTenant $cambiar): JsonResponse
    {
        $plantilla = PlantillaHorarioTenant::query()->where('ulid', (string) $request->route('plantilla'))->firstOrFail();
        $validado = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'dias_semana' => ['nullable', 'array', 'min:1'],
            'dias_semana.*' => ['integer', 'between:1,7'],
            'hora_local' => ['nullable', 'date_format:H:i'],
            'duracion_minutos' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'instructor_id' => ['nullable', 'string'],
            'recurso_id' => ['nullable', 'string'],
            'previsualizar' => ['sometimes', 'boolean'],
        ]);

        $cambios = [];
        if (isset($validado['dias_semana'])) {
            $dias = array_values(array_unique(array_map('intval', $validado['dias_semana'])));
            sort($dias);
            $cambios['dias_semana'] = $dias;
        }
        if (isset($validado['hora_local'])) {
            $cambios['hora_local'] = (string) $validado['hora_local'];
        }
        if (isset($validado['duracion_minutos'])) {
            $cambios['duracion_minutos'] = (int) $validado['duracion_minutos'];
        }
        // Profesional y sala: si vienen (aunque vacíos), se cambian (vacío = sin asignar).
        if ($request->has('instructor_id')) {
            $cambios['instructor_id'] = $this->resolverInstructor($validado['instructor_id'] ?? null);
        }
        if ($request->has('recurso_id')) {
            $cambios['recurso_id'] = $this->resolverRecurso($validado['recurso_id'] ?? null);
        }
        if ($cambios === []) {
            throw ValidationException::withMessages(['hora_local' => ['Indica qué cambia: días, hora, duración, profesional o sala.']]);
        }

        $actor = $request->attributes->get('usuario_tenant');
        $aplicar = ! (bool) ($validado['previsualizar'] ?? false);
        $resultado = $cambiar->desde($plantilla, (string) $validado['desde'], $cambios, $actor instanceof Usuario ? $actor : null, $aplicar);

        return response()->json(['data' => [
            'aplicado' => $aplicar,
            'movidas' => $resultado->movidas,
            'conservadas' => $resultado->conservadas,
            'quitadas' => $resultado->quitadas,
            'creadas' => $resultado->creadas,
            'omitidas' => $resultado->omitidas,
            'serie_id' => $resultado->serie,
        ]]);
    }

    private function resolverInstructor(?string $ulid): ?int
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }

        $instructor = Usuario::query()->where('ulid', $ulid)->first();

        return $instructor instanceof Usuario ? (int) $instructor->getKey() : null;
    }

    private function resolverRecurso(?string $ulid): ?int
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }

        $recurso = RecursoTenant::query()->where('ulid', $ulid)->first();

        return $recurso instanceof RecursoTenant ? (int) $recurso->getKey() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(PlantillaHorarioTenant $plantilla): array
    {
        return [
            'id' => $plantilla->ulid,
            'oferta' => $plantilla->oferta?->nombre,
            // Para ver la programación de la semana por actividad e instructor.
            'actividad' => $plantilla->oferta?->actividad?->nombre,
            'actividad_id' => $plantilla->oferta?->actividad?->ulid,
            'sucursal' => $plantilla->sucursal?->nombre,
            'instructor' => $plantilla->instructor?->name,
            'instructor_id' => $plantilla->instructor?->ulid,
            'dias_semana' => $plantilla->dias_semana,
            'hora_local' => $plantilla->hora_local,
            'duracion_minutos' => $plantilla->duracion_minutos,
            'capacidad' => $plantilla->capacidad,
            'activo' => $plantilla->activo,
            'vigente_desde' => $plantilla->vigente_desde->toDateString(),
            'vigente_hasta' => $plantilla->vigente_hasta?->toDateString(),
        ];
    }
}
