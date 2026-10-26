<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CambiarSerieTenant;
use App\Modules\Tenancy\Application\CancelarFechasTenant;
use App\Modules\Tenancy\Application\EliminacionesTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\GenerarAgendaTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\RecursoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Plantillas de horario recurrente del estudio (R5): definen la agenda que se
 * materializa en sesiones. `generar` produce las sesiones de un rango bajo demanda
 * (ademas del comando diario). Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class PlantillasHorarioTenantController
{
    /** El filtro de las series sin instructor (como en la web). */
    private const SIN_INSTRUCTOR = 'sin-asignar';

    public function __construct(
        private readonly GenerarAgendaTenant $generar,
        private readonly AccesoSesionTenant $acceso,
    ) {}

    /** Una serie de otra sede (o, para quien imparte, de otro profesional) no se toca. */
    private function exigirPlantillaEnAlcance(Request $request, PlantillaHorarioTenant $plantilla): void
    {
        $this->exigirAlcance($request, (int) $plantilla->sucursal_id, $plantilla->instructor_id !== null ? (int) $plantilla->instructor_id : null);
    }

    private function exigirAlcance(Request $request, int $sucursalId, ?int $instructorId): void
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($this->acceso->puedeOperarEn($sucursalId, $instructorId, $usuario instanceof Usuario ? $usuario : null), 403, 'Esa sucursal no es de las tuyas.');
    }

    /**
     * Las series de la agenda. Con `estado=vigentes`, solo las que siguen generando
     * fechas (la semana tipo, elegir una para un grupo); con `estado=terminadas`, las
     * que ya no se repiten, de la que terminó más reciente a la más vieja: se juntan
     * con cada cambio de horario, así que con `page` van por páginas (10 por omisión),
     * con los filtros de la semana (`actividad_id`, `instructor_id` o `sin-asignar`,
     * `sucursal`). Sin `estado`, todas como antes.
     */
    public function index(Request $request, FechasNegocioTenant $fechas): JsonResponse
    {
        $validado = $request->validate([
            'estado' => ['nullable', Rule::in(['vigentes', 'terminadas'])],
            'actividad_id' => ['nullable', 'string', 'max:40'],
            'instructor_id' => ['nullable', 'string', 'max:40'],
            'sucursal' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $hoy = $fechas->hoy();
        $estado = $validado['estado'] ?? null;

        $consulta = PlantillaHorarioTenant::query()
            ->with(['oferta.actividad', 'sucursal', 'instructor'])
            ->when($estado === 'vigentes', fn (Builder $q) => $q->where('activo', true)
                ->where(fn (Builder $v) => $v->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $hoy)))
            ->when($estado === 'terminadas', fn (Builder $q) => $q
                ->where(fn (Builder $t) => $t->where('activo', false)->orWhereDate('vigente_hasta', '<', $hoy)))
            ->when($validado['actividad_id'] ?? null, fn (Builder $q, string $actividad) => $q
                ->whereHas('oferta.actividad', fn (Builder $a) => $a->where('ulid', $actividad)))
            ->when($validado['instructor_id'] ?? null, fn (Builder $q, string $instructor) => $instructor === self::SIN_INSTRUCTOR
                ? $q->whereNull('instructor_id')
                : $q->whereHas('instructor', fn (Builder $i) => $i->where('ulid', $instructor)))
            ->when($validado['sucursal'] ?? null, fn (Builder $q, string $sucursal) => $q
                ->whereHas('sucursal', fn (Builder $c) => $c->where('nombre', $sucursal)))
            ->when($estado === 'terminadas', fn (Builder $q) => $q->orderByDesc('vigente_hasta'))
            ->orderByDesc('id');

        if ($estado === 'terminadas' && isset($validado['page'])) {
            $pagina = $consulta->paginate((int) ($validado['per_page'] ?? 10), ['*'], 'page', (int) $validado['page']);

            return response()->json([
                'data' => $pagina->getCollection()->map(fn (PlantillaHorarioTenant $p): array => $this->presentar($p))->values()->all(),
                'meta' => [
                    'total' => $pagina->total(),
                    'page' => $pagina->currentPage(),
                    'per_page' => $pagina->perPage(),
                    'ultima_pagina' => $pagina->lastPage(),
                ],
            ]);
        }

        return response()->json([
            'data' => $consulta->get()->map(fn (PlantillaHorarioTenant $p): array => $this->presentar($p))->all(),
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

        $oferta = OfertaTenant::paraAgendar($validado['oferta_id']);
        $sucursal = SucursalTenant::query()->where('ulid', $validado['sucursal_id'])->firstOrFail();
        // Solo en sus sedes y, quien imparte, para sí (R19, ADR 0095).
        $this->exigirAlcance($request, (int) $sucursal->getKey(), $this->resolverInstructor($validado['instructor_id'] ?? null));

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
        $this->exigirPlantillaEnAlcance($request, $plantilla);
        // Baja lógica: deja de usarse; queda en la bitácora qué era y quién lo eliminó.
        $eliminaciones->eliminar($plantilla, 'plantilla_horario', $plantilla->only(['dias_semana', 'hora_local', 'duracion_minutos', 'vigente_desde', 'vigente_hasta']));

        return response()->json(status: 204);
    }

    /**
     * Genera las fechas de `desde` a `hasta`; sin `hasta`, hasta el horizonte del negocio
     * (`agenda.dias_a_generar`): una serie sin fecha final la sigue extendiendo la tarea
     * diaria (ADR 0223).
     */
    public function generar(Request $request, ParametrosTenant $parametros, FechasNegocioTenant $fechas): JsonResponse
    {
        $plantilla = PlantillaHorarioTenant::query()->where('ulid', (string) $request->route('plantilla'))->firstOrFail();
        $this->exigirPlantillaEnAlcance($request, $plantilla);

        $validado = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);
        $hasta = $validado['hasta'] ?? max(
            (string) $validado['desde'],
            CarbonImmutable::parse($fechas->hoy())->addDays($parametros->entero('agenda.dias_a_generar'))->toDateString(),
        );

        $resultado = $this->generar->ejecutar($plantilla, $validado['desde'], $hasta);

        // Las fechas que no se pudieron generar, con su motivo (no se omiten en silencio).
        return response()->json(['data' => ['creadas' => $resultado->creadas, 'omitidas' => $resultado->omitidas]], 201);
    }

    /**
     * Cambia hasta cuándo se repite (ADR 0223): `vigente_hasta` (o null, sin fecha final)
     * no antes de su última fecha ya generada; para terminarla antes se usa
     * {@see self::terminar()}, que cancela lo que sobra. Genera hasta el nuevo fin o el
     * horizonte del negocio.
     */
    public function fin(Request $request, ParametrosTenant $parametros, FechasNegocioTenant $fechas): JsonResponse
    {
        $plantilla = PlantillaHorarioTenant::query()->where('ulid', (string) $request->route('plantilla'))->firstOrFail();
        $this->exigirPlantillaEnAlcance($request, $plantilla);
        $validado = $request->validate(['vigente_hasta' => ['present', 'nullable', 'date_format:Y-m-d']]);
        $hasta = $validado['vigente_hasta'];
        $ultima = SesionTenant::query()->where('serie_id', $plantilla->getKey())->max('fecha_serie');
        $ultima = is_string($ultima) ? substr($ultima, 0, 10) : null;
        if ($hasta !== null && ($hasta < $plantilla->vigente_desde->toDateString() || ($ultima !== null && $hasta < $ultima))) {
            throw ValidationException::withMessages(['vigente_hasta' => ['Para que termine antes de sus fechas ya programadas, cancélalas desde la fecha en que termina.']]);
        }

        $plantilla->update(['vigente_hasta' => $hasta]);
        $horizonte = CarbonImmutable::parse($fechas->hoy())->addDays($parametros->entero('agenda.dias_a_generar'))->toDateString();
        $resultado = $this->generar->ejecutar($plantilla->refresh(), $fechas->hoy(), $hasta !== null ? min($hasta, $horizonte) : $horizonte);

        return response()->json(['data' => [
            'vigente_hasta' => $plantilla->vigente_hasta?->toDateString(),
            'creadas' => $resultado->creadas,
            'omitidas' => $resultado->omitidas,
        ]]);
    }

    /**
     * «Cancelar esta y las siguientes» (ADR 0223): la clase deja de repetirse desde
     * `desde` y se cancelan sus fechas programadas desde ahí (cada una devuelve créditos
     * y avisa). Con `previsualizar`, solo cuántas fechas y personas.
     */
    public function terminar(Request $request, CancelarFechasTenant $cancelar, FechasNegocioTenant $fechas): JsonResponse
    {
        $plantilla = PlantillaHorarioTenant::query()->where('ulid', (string) $request->route('plantilla'))->firstOrFail();
        $this->exigirPlantillaEnAlcance($request, $plantilla);
        $validado = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$fechas->hoy()],
            'previsualizar' => ['sometimes', 'boolean'],
        ], ['desde.after_or_equal' => 'Solo se cancelan fechas de hoy en adelante.']);
        $desde = (string) $validado['desde'];
        $sesiones = $cancelar->deLaSerieDesde($plantilla, $desde);
        if ((bool) ($validado['previsualizar'] ?? false)) {
            return response()->json(['data' => $cancelar->resumen($sesiones)]);
        }

        $actor = $request->attributes->get('usuario_tenant');
        $resultado = DB::connection('tenant')->transaction(function () use ($plantilla, $desde, $sesiones, $cancelar, $actor): array {
            // Desde su primer día: deja de repetirse del todo.
            $plantilla->update($desde <= $plantilla->vigente_desde->toDateString()
                ? ['activo' => false]
                : ['vigente_hasta' => CarbonImmutable::parse($desde)->subDay()->toDateString()]);

            return $cancelar->cancelar($sesiones, $actor instanceof Usuario ? $actor : null);
        });

        return response()->json(['data' => $resultado]);
    }

    /**
     * "Esta y las siguientes" (2.5): cambia días (ADR 0045), hora, duración,
     * profesional o sala desde `desde`. Con `previsualizar` no guarda nada y dice lo mismo que pasaría.
     */
    public function cambiar(Request $request, CambiarSerieTenant $cambiar): JsonResponse
    {
        $plantilla = PlantillaHorarioTenant::query()->where('ulid', (string) $request->route('plantilla'))->firstOrFail();
        $this->exigirPlantillaEnAlcance($request, $plantilla);
        $validado = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'dias_semana' => ['nullable', 'array', 'min:1'],
            'dias_semana.*' => ['integer', 'between:1,7'],
            'hora_local' => ['nullable', 'date_format:H:i'],
            'duracion_minutos' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'instructor_id' => ['nullable', 'string'],
            'recurso_id' => ['nullable', 'string'],
            'capacidad' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
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
            $this->exigirAlcance($request, (int) $plantilla->sucursal_id, $cambios['instructor_id']);
        }
        if ($request->has('recurso_id')) {
            $cambios['recurso_id'] = $this->resolverRecurso($validado['recurso_id'] ?? null);
        }
        // El cupo (ADR 0223): vacío = sin tope.
        if ($request->has('capacidad')) {
            $cambios['capacidad'] = $validado['capacidad'] === null ? null : (int) $validado['capacidad'];
        }
        if ($cambios === []) {
            throw ValidationException::withMessages(['hora_local' => ['Indica qué cambia: días, hora, duración, profesional, sala o cupo.']]);
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
