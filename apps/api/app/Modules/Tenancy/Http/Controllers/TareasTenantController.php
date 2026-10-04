<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\GestionarTareasTenant;
use App\Modules\Tenancy\Automatizacion\EstadoTarea;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\TareaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Bandeja de tareas de seguimiento del estudio, tenant-local (R16): pendientes
 * accionables para el staff (manuales o generadas por automatización). Opera SIEMPRE
 * sobre la BD del estudio resuelto.
 *
 * Alcance: sin `tareas.equipo`, cada quien ve, completa y reabre solo SUS tareas
 * (de las que es responsable); las del equipo y las automáticas sin responsable
 * son de quien tiene ese permiso. Ocultarlas en la pantalla no bastaría.
 */
class TareasTenantController
{
    public function __construct(private readonly GestionarTareasTenant $tareas) {}

    public function index(Request $request): JsonResponse
    {
        $estado = (string) $request->query('estado', EstadoTarea::Pendiente->value);

        $suyas = $this->soloDe($request);
        $consulta = TareaTenant::query()->with(['persona', 'responsable'])
            ->when($suyas !== null, fn ($q) => $q->where('responsable_id', $suyas));
        if (EstadoTarea::tryFrom($estado) !== null) {
            $consulta->where('estado', $estado);
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $consulta->where('titulo', 'like', "%{$q}%");
        }

        $tareas = $consulta
            ->orderByRaw('vence_en is null')
            ->orderBy('vence_en')
            ->orderByDesc('id')
            ->get();

        $pendientes = TareaTenant::query()->where('estado', EstadoTarea::Pendiente->value)
            ->when($suyas !== null, fn ($q) => $q->where('responsable_id', $suyas))
            ->count();

        return response()->json([
            'data' => $tareas->map(fn (TareaTenant $t): array => $this->presentar($t))->all(),
            'pendientes' => $pendientes,
            // «mias»: solo las suyas; «equipo»: las de todos.
            'alcance' => $suyas !== null ? 'mias' : 'equipo',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'detalle' => ['nullable', 'string', 'max:2000'],
            'persona_id' => ['nullable', 'string'],
            'vence_en' => ['nullable', 'date'],
        ]);

        $personaId = null;
        if (! empty($validado['persona_id'])) {
            $personaId = PersonaTenant::query()->where('ulid', $validado['persona_id'])->value('id');
        }

        $tarea = $this->tareas->crear([
            'titulo' => $validado['titulo'],
            'detalle' => $validado['detalle'] ?? null,
            'persona_id' => $personaId !== null ? (int) $personaId : null,
            'responsable_id' => $this->actor($request)?->getKey(),
            'vence_en' => isset($validado['vence_en']) ? Carbon::parse($validado['vence_en']) : null,
        ]);

        return response()->json(['data' => $this->presentar($tarea->load(['persona', 'responsable']))], 201);
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
            'responsable' => $tarea->responsable?->name,
            'automatica' => $tarea->regla_id !== null,
            'completada_en' => $tarea->completada_en?->toIso8601String(),
            'creado_en' => $tarea->created_at?->toIso8601String(),
        ];
    }
}
