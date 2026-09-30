<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Operacion\ErroresPlataforma;
use App\Modules\Platform\Operacion\ErrorPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Los errores de la API, la web y la app para el superadmin (ADR 0080): la lista por
 * estado y origen, el detalle con su traza y contexto, y marcarlos resueltos,
 * ignorados o abiertos de nuevo.
 */
class PlataformaErroresController
{
    private const POR_PAGINA = 50;

    public function __construct(private readonly ErroresPlataforma $errores) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'estado' => ['nullable', 'string', Rule::in(ErrorPlataforma::ESTADOS)],
            'origen' => ['nullable', 'string', Rule::in(ErrorPlataforma::ORIGENES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $origen = $filtros['origen'] ?? null;
        $pagina = ErrorPlataforma::query()
            ->where('estado', $filtros['estado'] ?? ErrorPlataforma::ABIERTO)
            ->when($origen !== null, fn ($q) => $q->where('origen', $origen))
            ->orderByDesc('ultima_en')
            ->paginate(self::POR_PAGINA);

        $conteos = ErrorPlataforma::query()
            ->when($origen !== null, fn ($q) => $q->where('origen', $origen))
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return response()->json([
            'data' => collect($pagina->items())->map(fn (ErrorPlataforma $e): array => $this->presentar($e))->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'conteos' => collect(ErrorPlataforma::ESTADOS)
                    ->mapWithKeys(fn (string $estado): array => [$estado => (int) ($conteos[$estado] ?? 0)])
                    ->all(),
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $error = $this->error($request);

        return response()->json(['data' => [
            ...$this->presentar($error),
            'traza' => $error->traza,
            'contexto' => (object) ($error->contexto ?? []),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $estado = (string) $request->validate([
            'estado' => ['required', 'string', Rule::in(ErrorPlataforma::ESTADOS)],
        ])['estado'];

        return response()->json(['data' => $this->presentar($this->errores->cambiarEstado($this->error($request), $estado))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(ErrorPlataforma $e): array
    {
        return [
            'id' => $e->ulid,
            'origen' => $e->origen,
            'tipo' => $e->tipo,
            'mensaje' => $e->mensaje,
            'lugar' => $e->lugar,
            'veces' => $e->veces,
            'primera_en' => $e->primera_en->toIso8601String(),
            'ultima_en' => $e->ultima_en->toIso8601String(),
            'version_primera' => $e->version_primera,
            'version_ultima' => $e->version_ultima,
            'estado' => $e->estado,
            'resuelto_en' => $e->resuelto_en?->toIso8601String(),
            'regresiones' => $e->regresiones,
            'estudio' => $e->contexto['estudio'] ?? null,
        ];
    }

    private function error(Request $request): ErrorPlataforma
    {
        return ErrorPlataforma::query()->where('ulid', (string) $request->route('error'))->firstOrFail();
    }
}
