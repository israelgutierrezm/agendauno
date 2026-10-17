<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\BajasDeGrupoTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\InscribirEnGrupoTenant;
use App\Modules\Tenancy\Models\GrupoTenant;
use App\Modules\Tenancy\Models\InscripcionGrupoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Grupos / cursos del estudio (R25): un grupo sigue una plantilla de horario (serie);
 * inscribir a una persona la auto-reserva en las ocurrencias futuras (y dice cómo quedó
 * cada fecha). Una baja, un cambio de grupo o el cierre del grupo tienen fecha y
 * cancelan las reservas del grupo desde ahí. Opera SIEMPRE sobre la BD del estudio
 * resuelto.
 */
class GruposTenantController
{
    public function __construct(
        private readonly InscribirEnGrupoTenant $inscribir,
        private readonly BajasDeGrupoTenant $bajas,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    public function index(): JsonResponse
    {
        // Inscritos: quienes siguen (las bajas quedan en su historial, no cuentan).
        $grupos = GrupoTenant::query()->with('plantilla.oferta')
            ->withCount(['inscripciones' => fn ($q) => $q->where('activo', true)])
            ->orderByDesc('id')->get();

        return response()->json([
            'data' => $grupos->map(fn (GrupoTenant $g): array => [
                'id' => $g->ulid,
                'nombre' => $g->nombre,
                'oferta' => $g->plantilla?->oferta?->nombre,
                'inscritos' => (int) ($g->getAttribute('inscripciones_count') ?? 0),
                'activo' => $g->activo,
                'cerrado_en' => $g->cerrado_en?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function crear(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'plantilla_id' => ['required', 'string'],
        ]);

        $plantilla = PlantillaHorarioTenant::query()->where('ulid', $validado['plantilla_id'])->firstOrFail();

        $grupo = GrupoTenant::query()->create([
            'nombre' => $validado['nombre'],
            'plantilla_id' => $plantilla->getKey(),
            'activo' => true,
        ]);

        return response()->json(['data' => ['id' => $grupo->ulid, 'nombre' => $grupo->nombre]], 201);
    }

    public function inscribir(Request $request): JsonResponse
    {
        $grupo = GrupoTenant::query()->where('ulid', (string) $request->route('grupo'))->firstOrFail();
        $validado = $request->validate(['persona_id' => ['required', 'string']]);
        $persona = PersonaTenant::query()->where('ulid', $validado['persona_id'])->firstOrFail();

        $resultado = $this->inscribir->inscribir($grupo, $persona);

        return response()->json(['data' => [
            'grupo' => $grupo->ulid,
            'persona' => $persona->ulid,
            ...$this->comoQuedo($resultado),
        ]], 201);
    }

    /**
     * Baja desde una fecha (día del negocio; hoy por omisión): ya no viene y se le
     * cancelan sus reservas del grupo desde ahí, con su crédito de vuelta.
     */
    public function baja(Request $request): JsonResponse
    {
        $inscripcion = $this->inscripcion($request);
        $resultado = $this->bajas->darDeBaja($inscripcion, $this->desde($request), $this->actor($request));

        return response()->json(['data' => $this->presentarInscripcion($inscripcion->refresh()), 'meta' => $resultado]);
    }

    /** Pasa a otro grupo desde una fecha: baja en este y reservas en el nuevo. */
    public function cambiar(Request $request): JsonResponse
    {
        $inscripcion = $this->inscripcion($request);
        $validado = $request->validate(['grupo_id' => ['required', 'string']]);
        $destino = GrupoTenant::query()->where('ulid', $validado['grupo_id'])->firstOrFail();
        $resultado = $this->bajas->cambiar($inscripcion, $destino, $this->desde($request), $this->actor($request));

        return response()->json([
            'data' => $this->presentarInscripcion($inscripcion->refresh()),
            'meta' => ['baja' => $resultado['baja'], 'alta' => $this->comoQuedo($resultado['alta'])],
        ]);
    }

    /** Cierra el grupo: ya no inscribe y da de baja a quienes seguían desde la fecha. */
    public function cerrar(Request $request): JsonResponse
    {
        $grupo = GrupoTenant::query()->where('ulid', (string) $request->route('grupo'))->firstOrFail();
        $resultado = $this->bajas->cerrar($grupo, $this->desde($request), $this->actor($request));

        return response()->json(['data' => ['id' => $grupo->ulid, 'activo' => false], 'meta' => $resultado]);
    }

    public function reabrir(Request $request): JsonResponse
    {
        $grupo = GrupoTenant::query()->where('ulid', (string) $request->route('grupo'))->firstOrFail();
        $this->bajas->reabrir($grupo, $this->actor($request));

        return response()->json(['data' => ['id' => $grupo->ulid, 'activo' => true]]);
    }

    /**
     * @param  array{reservadas: int, en_espera: int, no_reservadas: int, sesiones: list<array<string, mixed>>}  $resultado
     * @return array<string, mixed>
     */
    private function comoQuedo(array $resultado): array
    {
        return [
            'reservadas' => $resultado['reservadas'],
            'en_espera' => $resultado['en_espera'],
            'no_reservadas' => $resultado['no_reservadas'],
            // Fecha por fecha: reservada, en espera o sin reservar y por qué.
            'sesiones' => $resultado['sesiones'],
        ];
    }

    /** Desde qué día del negocio (hoy por omisión; no antes). */
    private function desde(Request $request): string
    {
        $hoy = $this->fechas->hoy();
        $validado = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$hoy],
        ], ['desde.after_or_equal' => 'La fecha no puede ser anterior a hoy.']);

        return (string) ($validado['desde'] ?? $hoy);
    }

    private function inscripcion(Request $request): InscripcionGrupoTenant
    {
        $grupo = GrupoTenant::query()->where('ulid', (string) $request->route('grupo'))->firstOrFail();
        $inscripcion = $grupo->inscripciones()->where('ulid', (string) $request->route('inscripcion'))->firstOrFail();
        abort_unless($inscripcion->activo, 409, 'Ya se había dado de baja.');

        return $inscripcion;
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarInscripcion(InscripcionGrupoTenant $i): array
    {
        return [
            'id' => $i->ulid,
            'persona' => $i->persona?->nombreCompleto(),
            'persona_id' => $i->persona?->ulid,
            'activo' => $i->activo,
            'baja_desde' => $i->baja_desde?->toDateString(),
        ];
    }

    /**
     * Quién está inscrito en el grupo: las activas primero, en el orden en que se
     * inscribieron. Un grupo no tiene tope de lugares, así que con `page` va por páginas
     * (20 por omisión) y se busca por nombre, correo o celular (`q`). Sin `page`, todas
     * como antes.
     */
    public function inscripciones(Request $request): JsonResponse
    {
        $grupo = GrupoTenant::query()->where('ulid', (string) $request->route('grupo'))->firstOrFail();
        $validado = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $consulta = $grupo->inscripciones()->with('persona')
            ->when($validado['q'] ?? null, fn ($q, string $texto) => $q->whereHas('persona', fn ($p) => $p->buscar($texto)))
            ->orderByDesc('activo')
            ->orderBy('id');
        $presentar = fn (InscripcionGrupoTenant $i): array => $this->presentarInscripcion($i);

        if (! isset($validado['page'])) {
            return response()->json(['data' => $consulta->get()->map($presentar)->all()]);
        }

        $pagina = $consulta->paginate((int) ($validado['per_page'] ?? 20), ['*'], 'page', (int) $validado['page']);

        return response()->json([
            'data' => $pagina->getCollection()->map($presentar)->values()->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
            ],
        ]);
    }
}
