<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\PersonaDeUsuarioTenant;
use App\Modules\Tenancy\Application\RegistrarEventoTenant;
use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ResenaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reseñas: el alumno califica lo que tomó (tras asistir, una vez por reserva, dentro
 * de 30 días) y el negocio las revisa con sus promedios y puede ocultar un comentario
 * del público.
 */
class ResenasTenantController
{
    private const DIAS_PARA_CALIFICAR = 30;

    public function __construct(
        private readonly PersonaDeUsuarioTenant $personas,
        private readonly RegistrarEventoTenant $eventos,
    ) {}

    /**
     * Lo que el alumno puede calificar: reservas con asistencia de los últimos días
     * que aún no califica.
     */
    public function pendientes(Request $request): JsonResponse
    {
        $persona = $this->persona($request);

        $reservas = ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereHas('asistencia', fn ($q) => $q->where('estado', EstadoAsistencia::Presente->value))
            ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>=', Carbon::now()->subDays(self::DIAS_PARA_CALIFICAR)))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('resenas')->whereColumn('resenas.reserva_id', 'reservas.id'))
            ->with(['sesion.oferta', 'sesion.instructor'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json(['data' => $reservas->map(fn (ReservaTenant $r): array => [
            'reserva_id' => $r->ulid,
            'actividad' => $r->sesion?->oferta?->nombre,
            'con' => $r->sesion?->instructor?->nombreCorto(),
            'fecha' => $r->sesion?->inicia_en->toIso8601String(),
        ])->all()]);
    }

    public function calificar(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        $validado = $request->validate([
            'calificacion' => ['required', 'integer', 'between:1,5'],
            'comentario' => ['nullable', 'string', 'max:1000'],
        ]);

        $reserva = ReservaTenant::query()
            ->where('ulid', (string) $request->route('reserva'))
            ->where('persona_id', $persona->getKey())
            ->with(['sesion', 'asistencia'])
            ->firstOrFail();

        if ($reserva->asistencia?->estado !== EstadoAsistencia::Presente) {
            throw ValidationException::withMessages(['reserva' => ['Solo puedes calificar lo que tomaste.']]);
        }
        if ($reserva->sesion === null || $reserva->sesion->inicia_en->lt(Carbon::now()->subDays(self::DIAS_PARA_CALIFICAR))) {
            throw ValidationException::withMessages(['reserva' => ['Ya pasó el tiempo para calificar esta clase.']]);
        }
        if (ResenaTenant::query()->where('reserva_id', $reserva->getKey())->exists()) {
            throw ValidationException::withMessages(['reserva' => ['Ya calificaste esta clase.']]);
        }

        $comentario = $validado['comentario'] ?? null;
        $resena = DB::connection('tenant')->transaction(function () use ($reserva, $persona, $validado, $comentario): ResenaTenant {
            $resena = ResenaTenant::query()->create([
                'reserva_id' => $reserva->getKey(),
                'persona_id' => $persona->getKey(),
                'oferta_id' => $reserva->sesion->oferta_id,
                'instructor_id' => $reserva->sesion->instructor_id,
                'calificacion' => (int) $validado['calificacion'],
                'comentario' => is_string($comentario) && trim($comentario) !== '' ? trim($comentario) : null,
            ]);
            // Para automatizaciones y webhooks (p. ej. atender una calificación baja).
            $this->eventos->registrar('resena.creada', 'resena', (string) $resena->ulid, [
                'persona_id' => (string) $persona->ulid,
                'calificacion' => $resena->calificacion,
                'comentario' => (string) $resena->comentario,
            ]);

            return $resena;
        });

        return response()->json(['data' => $this->presentar($resena->load(['oferta', 'instructor', 'persona']))], 201);
    }

    /**
     * Reseñas del negocio con su promedio general, por profesional y por servicio.
     */
    public function index(): JsonResponse
    {
        $resenas = ResenaTenant::query()->with(['oferta', 'instructor', 'persona'])->orderByDesc('id')->limit(200)->get();
        $todas = ResenaTenant::query()->get(['calificacion', 'instructor_id', 'oferta_id']);

        $promedio = static fn ($grupo): array => [
            'promedio' => round((float) $grupo->avg('calificacion'), 1),
            'total' => $grupo->count(),
        ];

        return response()->json([
            'data' => $resenas->map(fn (ResenaTenant $r): array => $this->presentar($r))->all(),
            'resumen' => [
                'general' => $promedio($todas),
                'por_profesional' => $todas->whereNotNull('instructor_id')->groupBy('instructor_id')
                    ->map(fn ($grupo, $id): array => ['nombre' => Usuario::query()->find($id)?->nombreCorto(), ...$promedio($grupo)])
                    ->sortByDesc('total')->values()->all(),
            ],
        ]);
    }

    public function visibilidad(Request $request): JsonResponse
    {
        $validado = $request->validate(['visible' => ['required', 'boolean']]);
        $resena = ResenaTenant::query()->where('ulid', (string) $request->route('resena'))->firstOrFail();
        $resena->update(['visible' => (bool) $validado['visible']]);

        return response()->json(['data' => $this->presentar($resena->load(['oferta', 'instructor', 'persona']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(ResenaTenant $r): array
    {
        return [
            'id' => $r->ulid,
            'calificacion' => $r->calificacion,
            'comentario' => $r->comentario,
            'visible' => $r->visible,
            'actividad' => $r->oferta?->nombre,
            'con' => $r->instructor?->nombreCorto(),
            'persona' => $r->persona?->nombreCompleto(),
            'fecha' => $r->created_at?->toIso8601String(),
        ];
    }

    private function persona(Request $request): PersonaTenant
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);
        $persona = $this->personas->buscar($usuario);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de alumno en este negocio.');

        return $persona;
    }
}
