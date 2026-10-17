<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\InscribirEnGrupoTenant;
use App\Modules\Tenancy\Models\GrupoTenant;
use App\Modules\Tenancy\Models\InscripcionGrupoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Grupos / cursos del estudio (R25): un grupo sigue una plantilla de horario (serie);
 * inscribir a una persona la auto-reserva en las ocurrencias futuras. Opera SIEMPRE
 * sobre la BD del estudio resuelto.
 */
class GruposTenantController
{
    public function __construct(private readonly InscribirEnGrupoTenant $inscribir) {}

    public function index(): JsonResponse
    {
        $grupos = GrupoTenant::query()->with('plantilla.oferta')->withCount('inscripciones')->orderByDesc('id')->get();

        return response()->json([
            'data' => $grupos->map(fn (GrupoTenant $g): array => [
                'id' => $g->ulid,
                'nombre' => $g->nombre,
                'oferta' => $g->plantilla?->oferta?->nombre,
                'inscritos' => (int) ($g->getAttribute('inscripciones_count') ?? 0),
                'activo' => $g->activo,
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
            'reservadas' => $resultado['reservadas'],
        ]], 201);
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
        $presentar = static fn (InscripcionGrupoTenant $i): array => [
            'id' => $i->ulid,
            'persona' => $i->persona?->nombreCompleto(),
            'persona_id' => $i->persona?->ulid,
            'activo' => $i->activo,
        ];

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
