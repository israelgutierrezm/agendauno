<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcanceClientesTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\PersonaDeUsuarioTenant;
use App\Modules\Tenancy\Application\RegistrarEventoTenant;
use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ResenaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\MarcaProducto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reseñas: el alumno califica lo que tomó (tras asistir, una vez por reserva, dentro
 * de 30 días) y el negocio las revisa con sus promedios y puede ocultar un comentario
 * del público.
 */
class ResenasTenantController
{
    public function __construct(
        private readonly PersonaDeUsuarioTenant $personas,
        private readonly RegistrarEventoTenant $eventos,
        private readonly ParametrosTenant $parametros,
    ) {}

    /**
     * Lo que el alumno puede calificar: reservas con asistencia de los últimos días
     * que aún no califica.
     */
    public function pendientes(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        $filtros = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);
        $dias = $this->parametros->entero('resenas.dias_para_calificar');
        // Las fechas del filtro son del calendario del negocio (su zona).
        $zona = app(FechasNegocioTenant::class)->zona();

        $consulta = ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereHas('asistencia', fn ($q) => $q->where('estado', EstadoAsistencia::Presente->value))
            ->whereHas('sesion', function ($q) use ($dias, $filtros, $zona): void {
                $q->where('inicia_en', '>=', Carbon::now()->subDays($dias));
                if (isset($filtros['desde'])) {
                    $q->where('inicia_en', '>=', Carbon::parse($filtros['desde'], $zona)->startOfDay()->utc());
                }
                if (isset($filtros['hasta'])) {
                    $q->where('inicia_en', '<', Carbon::parse($filtros['hasta'], $zona)->addDay()->startOfDay()->utc());
                }
            })
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('resenas')->whereColumn('resenas.reserva_id', 'reservas.id'))
            ->with(['sesion.oferta', 'sesion.instructor'])
            ->orderByDesc('id');

        // Paginadas: si se juntan varias, no es una lista interminable.
        $porPagina = (int) ($filtros['per_page'] ?? 20);
        $total = (clone $consulta)->count();
        $ultima = max(1, (int) ceil($total / $porPagina));
        $pagina = min((int) ($filtros['page'] ?? 1), $ultima);
        $reservas = $consulta->forPage($pagina, $porPagina)->get();

        return response()->json([
            'data' => $reservas->map(fn (ReservaTenant $r): array => [
                'reserva_id' => $r->ulid,
                'actividad' => $r->sesion?->oferta?->nombre,
                'con' => $r->sesion?->instructor?->nombreCorto(),
                'fecha' => $r->sesion?->inicia_en->toIso8601String(),
            ])->all(),
            'meta' => [
                'page' => $pagina, 'ultima_pagina' => $ultima, 'total' => $total, 'per_page' => $porPagina,
                // Hasta cuántos días atrás se puede calificar (el límite del filtro).
                'dias_para_calificar' => $dias,
            ],
        ]);
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
            ->with(['sesion.oferta', 'asistencia'])
            ->firstOrFail();

        if ($reserva->asistencia?->estado !== EstadoAsistencia::Presente) {
            throw ValidationException::withMessages(['reserva' => ['Solo puedes calificar lo que tomaste.']]);
        }
        if ($reserva->sesion === null || $reserva->sesion->inicia_en->lt(Carbon::now()->subDays($this->parametros->entero('resenas.dias_para_calificar')))) {
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
                // Sin revisión, se publica en su página; con revisión, espera a que el
                // negocio la apruebe (parámetro del negocio).
                'visible' => app(ParametrosTenant::class)->siNo('resenas.publicar_sin_revisar'),
            ]);
            // Para automatizaciones y webhooks (p. ej. atender una calificación baja).
            $this->eventos->registrar('resena.creada', 'resena', (string) $resena->ulid, [
                'persona_id' => (string) $persona->ulid,
                'calificacion' => $resena->calificacion,
                'comentario' => (string) $resena->comentario,
                // Para el aviso al equipo: qué se calificó y dónde verlo.
                'actividad' => (string) $reserva->sesion->oferta?->nombre,
                'enlace_panel' => MarcaProducto::actual()->urlWeb().'/resenas',
            ]);

            return $resena;
        });

        return response()->json(['data' => $this->presentar($resena->load(['oferta', 'instructor', 'persona']))], 201);
    }

    /**
     * Reseñas del negocio con su promedio general, por profesional y por servicio.
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['nullable', 'string', 'max:200'],
            'calificacion' => ['nullable', Rule::in(['todas', '5', '4', '3'])],
            'profesional' => ['nullable', 'string', 'max:200'],
        ]);
        // Quien imparte (acotado) ve solo las reseñas de sus clases o citas.
        $actor = $request->attributes->get('usuario_tenant');
        $propias = $actor instanceof Usuario && app(AlcanceClientesTenant::class)->esAcotado($actor) ? (int) $actor->getKey() : null;
        $consulta = ResenaTenant::query()->with(['oferta', 'instructor', 'persona'])->orderByDesc('id')
            ->when($propias !== null, fn ($q) => $q->where('instructor_id', $propias));
        $todas = ResenaTenant::query()->with('instructor')
            ->when($propias !== null, fn ($q) => $q->where('instructor_id', $propias))
            ->get(['calificacion', 'instructor_id', 'oferta_id', 'comentario']);
        $nota = $filtros['calificacion'] ?? 'todas';
        if ($nota !== 'todas') {
            $consulta->where('calificacion', $nota === '3' ? '<=' : '=', (int) $nota);
        }
        $profesional = $filtros['profesional'] ?? '';
        if ($profesional !== '') {
            $ids = $todas->pluck('instructor')->filter()->unique('id')
                ->filter(fn (Usuario $u): bool => $u->nombreCorto() === $profesional)->pluck('id');
            $consulta->whereIn('instructor_id', $ids);
        }
        foreach (preg_split('/\s+/u', trim($filtros['q'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $termino) {
            $patron = '%'.$termino.'%';
            $consulta->where(function ($q) use ($patron): void {
                $q->where('comentario', 'like', $patron)
                    ->orWhereHas('oferta', fn ($o) => $o->where('nombre', 'like', $patron))
                    ->orWhereHas('persona', fn ($p) => $p->where('nombre', 'like', $patron)
                        ->orWhere('segundo_nombre', 'like', $patron)->orWhere('primer_apellido', 'like', $patron)
                        ->orWhere('segundo_apellido', 'like', $patron));
            });
        }
        $perPage = (int) ($filtros['per_page'] ?? 20);
        $total = (clone $consulta)->count();
        $ultima = max(1, (int) ceil($total / $perPage));
        $page = min((int) ($filtros['page'] ?? 1), $ultima);
        $resenas = $consulta->forPage($page, $perPage)->get();

        $promedio = static fn ($grupo): array => [
            'promedio' => round((float) $grupo->avg('calificacion'), 1),
            'total' => $grupo->count(),
        ];

        return response()->json([
            'data' => $resenas->map(fn (ResenaTenant $r): array => $this->presentar($r))->all(),
            'meta' => ['page' => $page, 'ultima_pagina' => $ultima, 'total' => $total, 'per_page' => $perPage],
            'resumen' => [
                'conteos' => ['todas' => $todas->count(), '5' => $todas->where('calificacion', 5)->count(), '4' => $todas->where('calificacion', 4)->count(), '3' => $todas->where('calificacion', '<=', 3)->count()],
                'con_comentario' => $todas->filter(fn ($r): bool => filled($r->comentario))->count(),
                'general' => $promedio($todas),
                'por_profesional' => $todas->whereNotNull('instructor_id')->groupBy('instructor_id')
                    ->map(fn ($grupo): array => ['nombre' => $grupo->first()->instructor?->nombreCorto(), ...$promedio($grupo)])
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
