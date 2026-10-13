<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcancePersonaTenant;
use App\Modules\Tenancy\Application\FamiliasTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\GestionarTareasTenant;
use App\Modules\Tenancy\Automatizacion\EstadoTarea;
use App\Modules\Tenancy\Automatizacion\ResultadoSeguimiento;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\TareaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bandeja de tareas de seguimiento del estudio, tenant-local (R16): pendientes
 * accionables para el staff (manuales o generadas por automatización). Opera SIEMPRE
 * sobre la BD del estudio resuelto.
 *
 * Alcance: sin `tareas.equipo`, cada quien ve, completa y reabre solo SUS tareas
 * (de las que es responsable); las del equipo y las automáticas sin responsable
 * son de quien tiene ese permiso. Ocultarlas en la pantalla no bastaría.
 *
 * Asignar: quien tiene `tareas.equipo` asigna (y reasigna) una tarea a cualquiera del
 * equipo que atienda tareas (`tareas.gestionar`); sin él, las tareas que crea son suyas.
 * Una tarea puede ir con una persona del negocio (a quién llamar, a quién escribirle).
 */
class TareasTenantController
{
    private const POR_PAGINA = 20;

    public function __construct(
        private readonly GestionarTareasTenant $tareas,
        private readonly FamiliasTenant $familias,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    /**
     * La bandeja, por páginas: pendientes (la más próxima a vencer primero) o
     * completadas (la más reciente primero). Se busca por título, detalle o persona y
     * se acota por tipo, por vencimiento o por fechas (de vencimiento o, en las
     * completadas, de cuándo se completaron). `meta.conteos` son las pendientes de su
     * alcance por vencimiento, sin los demás filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'estado' => ['nullable', Rule::enum(EstadoTarea::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'tipo' => ['nullable', Rule::in(['seguimiento', 'automatica', 'manual'])],
            'vencimiento' => ['nullable', Rule::in(['vencidas', 'hoy', 'semana', 'sin_fecha'])],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'mias' => ['nullable', 'boolean'],
            // Las de alguien del equipo (solo con `tareas.equipo`).
            'responsable' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $desde = $filtros['desde'] ?? null;
        $hasta = $filtros['hasta'] ?? null;
        if ($desde !== null && $hasta !== null && $hasta < $desde) {
            throw ValidationException::withMessages(['hasta' => ['La fecha final no puede ser antes que la inicial.']]);
        }
        $estado = EstadoTarea::from((string) ($filtros['estado'] ?? EstadoTarea::Pendiente->value));
        $completadas = $estado === EstadoTarea::Completada;

        $suyas = $this->soloDe($request);
        if ($suyas === null && (bool) ($filtros['mias'] ?? false)) {
            $suyas = (int) $this->actor($request)?->getKey();
        }
        if ($suyas === null && ($filtros['responsable'] ?? '') !== '') {
            $suyas = (int) (Usuario::query()->where('ulid', $filtros['responsable'])->value('id') ?? 0);
        }
        // De sus sedes (R19): las que no cuelgan de nadie las ve todo el equipo y las que
        // le asignaron, quien las tiene.
        $deSuAlcance = fn (): Builder => app(AlcancePersonaTenant::class)->acotadaSalvoLoSuyo(TareaTenant::query(), $request, 'responsable_id')
            ->when($suyas !== null, fn (Builder $q) => $q->where('responsable_id', $suyas));

        $q = trim((string) ($filtros['q'] ?? ''));
        $columna = $completadas ? 'completada_en' : 'vence_en';
        $consulta = $deSuAlcance()->with(['persona', 'responsable'])
            ->where('estado', $estado->value)
            ->when($q !== '', fn (Builder $c) => $c->where(fn (Builder $w) => $w
                ->where('titulo', 'like', "%{$q}%")
                ->orWhere('detalle', 'like', "%{$q}%")
                ->orWhereHas('persona', fn (Builder $p) => $p
                    ->where('nombre', 'like', "%{$q}%")
                    ->orWhere('primer_apellido', 'like', "%{$q}%")
                    ->orWhere('segundo_apellido', 'like', "%{$q}%"))))
            ->when(($filtros['tipo'] ?? null) !== null, fn (Builder $c) => $this->deTipo($c, (string) $filtros['tipo']))
            ->when(($filtros['vencimiento'] ?? null) !== null, fn (Builder $c) => $this->vencen($c, (string) $filtros['vencimiento']))
            ->when($desde !== null, fn (Builder $c) => $c->where($columna, '>=', $this->fechas->inicioDelDia((string) $desde)))
            ->when($hasta !== null, fn (Builder $c) => $c->where($columna, '<=', $this->fechas->finDelDia((string) $hasta)));

        if ($completadas) {
            $consulta->orderByDesc('completada_en');
        } else {
            $consulta->orderByRaw('vence_en is null')->orderBy('vence_en');
        }
        $pagina = $consulta->orderByDesc('id')
            ->paginate((int) ($filtros['per_page'] ?? self::POR_PAGINA), ['*'], 'page', (int) ($filtros['page'] ?? 1));

        $pendientes = fn (): Builder => $deSuAlcance()->where('estado', EstadoTarea::Pendiente->value);

        return response()->json([
            'data' => collect($pagina->items())->map(fn (TareaTenant $t): array => $this->presentar($t))->all(),
            'pendientes' => $pendientes()->count(),
            // «mias»: solo las suyas; «equipo»: las de todos.
            'alcance' => $this->soloDe($request) !== null ? 'mias' : 'equipo',
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'conteos' => [
                    'vencidas' => $this->vencen($pendientes(), 'vencidas')->count(),
                    'hoy' => $this->vencen($pendientes(), 'hoy')->count(),
                    'semana' => $this->vencen($pendientes(), 'semana')->count(),
                    'sin_fecha' => $this->vencen($pendientes(), 'sin_fecha')->count(),
                ],
            ],
        ]);
    }

    /**
     * Seguimientos (M09: tienen un motivo), automáticas (de una regla) o manuales.
     *
     * @param  Builder<TareaTenant>  $consulta
     * @return Builder<TareaTenant>
     */
    private function deTipo(Builder $consulta, string $tipo): Builder
    {
        return match ($tipo) {
            'seguimiento' => $consulta->whereNotNull('motivo'),
            'automatica' => $consulta->whereNotNull('regla_id'),
            default => $consulta->whereNull('regla_id')->whereNull('motivo'),
        };
    }

    /**
     * Por su vencimiento, en días del negocio: vencidas (antes de hoy), hoy, en los
     * próximos 7 días (desde mañana) o sin fecha.
     *
     * @param  Builder<TareaTenant>  $consulta
     * @return Builder<TareaTenant>
     */
    private function vencen(Builder $consulta, string $cuando): Builder
    {
        $hoy = $this->fechas->hoy();

        return match ($cuando) {
            'vencidas' => $consulta->where('vence_en', '<', $this->fechas->inicioDelDia($hoy)),
            'hoy' => $consulta->whereBetween('vence_en', [$this->fechas->inicioDelDia($hoy), $this->fechas->finDelDia($hoy)]),
            'semana' => $consulta->whereBetween('vence_en', [
                $this->fechas->inicioDelDia(Carbon::parse($hoy)->addDay()->toDateString()),
                $this->fechas->finDelDia(Carbon::parse($hoy)->addDays(7)->toDateString()),
            ]),
            default => $consulta->whereNull('vence_en'),
        };
    }

    public function store(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'detalle' => ['nullable', 'string', 'max:2000'],
            'persona_id' => ['nullable', 'string', 'max:40'],
            'responsable_id' => ['nullable', 'string', 'max:40'],
            'vence_en' => ['nullable', 'date'],
        ]);

        $tarea = $this->tareas->crear([
            'titulo' => $validado['titulo'],
            'detalle' => $validado['detalle'] ?? null,
            'persona_id' => $this->persona($validado['persona_id'] ?? null),
            'responsable_id' => $this->responsable($request, $validado['responsable_id'] ?? null),
            'vence_en' => $this->vencimiento($validado['vence_en'] ?? null),
        ]);

        return response()->json(['data' => $this->presentar($tarea->load(['persona', 'responsable']))], 201);
    }

    /**
     * Edita una tarea pendiente: título, detalle, vencimiento, persona y (con
     * `tareas.equipo`) a quién se asigna. Lo que no viene no cambia.
     */
    public function update(Request $request): JsonResponse
    {
        $tarea = $this->resolver($request);
        if ($tarea->estado !== EstadoTarea::Pendiente) {
            throw ValidationException::withMessages(['titulo' => ['Solo se edita una tarea pendiente.']]);
        }
        $validado = $request->validate([
            'titulo' => ['sometimes', 'required', 'string', 'max:255'],
            'detalle' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'persona_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'responsable_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'vence_en' => ['sometimes', 'nullable', 'date'],
        ]);

        if (array_key_exists('titulo', $validado)) {
            $tarea->titulo = (string) $validado['titulo'];
        }
        if (array_key_exists('detalle', $validado)) {
            $tarea->detalle = $validado['detalle'];
        }
        if (array_key_exists('vence_en', $validado)) {
            $tarea->vence_en = $this->vencimiento($validado['vence_en']);
        }
        if (array_key_exists('persona_id', $validado)) {
            $tarea->persona_id = $this->persona($validado['persona_id']);
        }
        if (array_key_exists('responsable_id', $validado)) {
            $tarea->responsable_id = $this->responsable($request, $validado['responsable_id']);
        }
        $tarea->save();

        return response()->json(['data' => $this->presentar($tarea->fresh(['persona', 'responsable']) ?? $tarea)]);
    }

    /**
     * Quienes pueden recibir una tarea: el equipo activo que atiende tareas.
     */
    public function responsables(): JsonResponse
    {
        $equipo = Usuario::query()
            ->where('activo', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (Usuario $u): bool => $u->puede('tareas.gestionar'))
            ->map(fn (Usuario $u): array => ['id' => (string) $u->ulid, 'nombre' => (string) $u->name])
            ->values()
            ->all();

        return response()->json(['data' => $equipo]);
    }

    /** El id de la persona del negocio con ese ULID; 422 si no existe. */
    private function persona(?string $ulid): ?int
    {
        if ($ulid === null || $ulid === '') {
            return null;
        }
        $id = PersonaTenant::query()->where('ulid', $ulid)->value('id');
        if ($id === null) {
            throw ValidationException::withMessages(['persona_id' => ['La persona no existe.']]);
        }

        return (int) $id;
    }

    /**
     * A quién se asigna: quien pide, si no dice otra cosa. A otra persona solo con
     * `tareas.equipo`, y solo a quien atiende tareas.
     */
    private function responsable(Request $request, ?string $ulid): ?int
    {
        $actor = $this->actor($request);
        if ($ulid === null || $ulid === '' || $ulid === $actor?->ulid) {
            return $actor?->getKey() !== null ? (int) $actor->getKey() : null;
        }
        if ($this->soloDe($request) !== null) {
            throw ValidationException::withMessages(['responsable_id' => ['Solo puedes crear tareas para ti.']]);
        }
        $usuario = Usuario::query()->where('ulid', $ulid)->where('activo', true)->first();
        if (! $usuario instanceof Usuario || ! $usuario->puede('tareas.gestionar')) {
            throw ValidationException::withMessages(['responsable_id' => ['Esa persona del equipo no atiende tareas.']]);
        }

        return (int) $usuario->getKey();
    }

    public function completar(Request $request): JsonResponse
    {
        $tarea = $this->resolver($request);
        $this->tareas->completar($tarea, $this->actor($request));

        return response()->json(['data' => $this->presentar($tarea->fresh(['persona', 'responsable']) ?? $tarea)]);
    }

    public function reabrir(Request $request): JsonResponse
    {
        $tarea = $this->resolver($request);
        $this->tareas->reabrir($tarea);

        return response()->json(['data' => $this->presentar($tarea->fresh(['persona', 'responsable']) ?? $tarea)]);
    }

    /**
     * Con qué terminó un seguimiento (M09): lo cierra y, si no desea contacto, deja de
     * recibir promociones.
     */
    public function resultado(Request $request): JsonResponse
    {
        $tarea = $this->resolver($request);
        $validado = $request->validate([
            'resultado' => ['required', Rule::enum(ResultadoSeguimiento::class)],
            'nota' => ['nullable', 'string', 'max:500'],
        ]);
        $tarea = $this->tareas->registrarResultado(
            $tarea,
            ResultadoSeguimiento::from((string) $validado['resultado']),
            $validado['nota'] ?? null,
            $this->actor($request),
        );

        return response()->json(['data' => $this->presentar($tarea->fresh(['persona', 'responsable']) ?? $tarea)]);
    }

    /**
     * Una fecha sola (`2026-10-11`) vence al terminar ese día en el negocio; una fecha
     * con hora, en ese momento.
     */
    private function vencimiento(?string $valor): ?Carbon
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1
            ? Carbon::instance($this->fechas->finDelDia($valor))
            : Carbon::parse($valor);
    }

    private function resolver(Request $request): TareaTenant
    {
        $tarea = TareaTenant::query()->where('ulid', (string) $request->route('tarea'))->firstOrFail();
        $suyas = $this->soloDe($request);
        abort_if($suyas !== null && (int) $tarea->responsable_id !== $suyas, 403, 'Esta tarea es de otra persona del equipo.');

        return $tarea;
    }

    /**
     * Sin `tareas.equipo`, el id de quien pide (solo sus tareas); con él, null.
     */
    private function soloDe(Request $request): ?int
    {
        $actor = $this->actor($request);

        return $actor !== null && ! $actor->puede('tareas.equipo') ? (int) $actor->getKey() : null;
    }

    private function actor(Request $request): ?Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');

        return $usuario instanceof Usuario ? $usuario : null;
    }

    /**
     * @return array{nombre: string, celular: string}|null
     */
    private function contactoDe(TareaTenant $tarea): ?array
    {
        $persona = $tarea->persona;
        if ($tarea->motivo === null || ! $persona instanceof PersonaTenant || trim((string) $persona->celular) !== '') {
            return null;
        }
        $responsable = $this->familias->responsablesDe($persona)
            ->first(static fn (PersonaTenant $r): bool => trim((string) $r->celular) !== '');

        return $responsable instanceof PersonaTenant
            ? ['nombre' => $responsable->nombreCompleto(), 'celular' => (string) $responsable->celular]
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(TareaTenant $tarea): array
    {
        return [
            'id' => $tarea->ulid,
            'titulo' => $tarea->titulo,
            'detalle' => $tarea->detalle,
            'estado' => $tarea->estado->value,
            'vence_en' => $tarea->vence_en?->toIso8601String(),
            'persona' => $tarea->persona?->nombreCompleto(),
            // Para ir a su ficha y escribirle (seguimiento; M09).
            'persona_id' => $tarea->persona?->ulid,
            'celular' => $tarea->persona?->celular,
            // Sin celular propio, a quién escribirle: su responsable (familias, ADR 0123).
            'contacto' => $this->contactoDe($tarea),
            'motivo' => $tarea->motivo?->value,
            'resultado' => $tarea->resultado?->value,
            'contactado_en' => $tarea->contactado_en?->toIso8601String(),
            'responsable' => $tarea->responsable?->name,
            'responsable_id' => $tarea->responsable?->ulid,
            'automatica' => $tarea->regla_id !== null,
            'completada_en' => $tarea->completada_en?->toIso8601String(),
            'creado_en' => $tarea->created_at?->toIso8601String(),
        ];
    }
}
