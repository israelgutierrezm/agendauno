<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CortesDeNominaTenant;
use App\Modules\Tenancy\Models\AjusteNominaTenant;
use App\Modules\Tenancy\Models\CorteNominaTenant;
use App\Modules\Tenancy\Models\LineaCorteNominaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cortes de nómina: los periodos cerrados y pagados, con lo que quedó por persona, el
 * pago y sus ajustes (la regla vive en {@see CortesDeNominaTenant}).
 */
class CortesNominaTenantController
{
    public function __construct(private readonly CortesDeNominaTenant $cortes) {}

    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $pagina = CorteNominaTenant::query()
            ->withSum('ajustes', 'monto_minor')
            ->orderByDesc('hasta')
            ->paginate((int) ($validado['per_page'] ?? 10), ['*'], 'page', (int) ($validado['page'] ?? 1));

        return response()->json([
            'data' => $pagina->getCollection()->map(fn (CorteNominaTenant $c): array => $this->presentar($c))->values()->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
            ],
        ]);
    }

    public function mostrar(Request $request): JsonResponse
    {
        $corte = $this->corte($request);
        $corte->loadSum('ajustes', 'monto_minor');
        $usuarios = Usuario::query()
            ->whereIn('id', $corte->lineas->pluck('usuario_id')->merge($corte->ajustes->pluck('usuario_id'))->filter())
            ->pluck('ulid', 'id');

        return response()->json(['data' => [
            ...$this->presentar($corte),
            'lineas' => $corte->lineas->map(fn (LineaCorteNominaTenant $l): array => [
                'usuario_id' => $l->usuario_id !== null ? $usuarios->get($l->usuario_id) : null,
                'usuario' => $l->usuario_nombre,
                'tipo' => $l->tipo,
                'monto_minor' => $l->monto_minor,
                'moneda' => $l->moneda,
                'vigente_desde' => $l->vigente_desde?->toDateString(),
                'unidades' => (float) $l->unidades,
                'total_minor' => $l->total_minor,
            ])->values()->all(),
            'ajustes' => $corte->ajustes->sortBy('id')->map(fn (AjusteNominaTenant $a): array => [
                'id' => $a->ulid,
                'usuario_id' => $a->usuario_id !== null ? $usuarios->get($a->usuario_id) : null,
                'usuario' => $a->usuario_nombre,
                'monto_minor' => $a->monto_minor,
                'moneda' => $a->moneda,
                'motivo' => $a->motivo,
                'registrado_por' => $a->registrado_por_nombre,
                'registrado_en' => $a->created_at?->toIso8601String(),
            ])->values()->all(),
            // Lo que cambió desde el cierre (p. ej. una asistencia corregida): para decidir un ajuste.
            'diferencias' => $this->cortes->diferencias($corte),
        ]]);
    }

    public function cerrar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);
        $corte = $this->cortes->cerrar($validado['desde'], $validado['hasta'], $this->actor($request));

        return response()->json(['data' => $this->presentar($corte)], 201);
    }

    public function pagar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'referencia' => ['required', 'string', 'max:120'],
        ]);
        $corte = $this->cortes->pagar($this->corte($request), trim($validado['referencia']), $this->actor($request));

        return response()->json(['data' => $this->presentar($corte)]);
    }

    public function reabrir(Request $request): JsonResponse
    {
        $this->cortes->reabrir($this->corte($request), $this->actor($request));

        return response()->json(status: 204);
    }

    public function ajustar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'usuario_id' => ['required', 'string'],
            'monto_minor' => ['required', 'integer', 'between:-100000000000,100000000000'],
            'motivo' => ['required', 'string', 'max:250'],
            // Corrige una diferencia del cálculo (R09): se resta de lo que falta.
            'compensa_diferencia' => ['sometimes', 'boolean'],
        ]);
        $persona = Usuario::query()->where('ulid', $validado['usuario_id'])->firstOrFail();
        $ajuste = $this->cortes->ajustar(
            $this->corte($request), $persona, (int) $validado['monto_minor'], trim($validado['motivo']), $this->actor($request),
            (bool) ($validado['compensa_diferencia'] ?? false),
        );

        return response()->json(['data' => ['id' => $ajuste->ulid, 'monto_minor' => $ajuste->monto_minor]], 201);
    }

    private function corte(Request $request): CorteNominaTenant
    {
        return CorteNominaTenant::query()->where('ulid', (string) $request->route('corte'))->firstOrFail();
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(CorteNominaTenant $c): array
    {
        $ajustes = (int) ($c->getAttribute('ajustes_sum_monto_minor') ?? 0);

        return [
            'id' => $c->ulid,
            'desde' => $c->desde->toDateString(),
            'hasta' => $c->hasta->toDateString(),
            'estado' => $c->estado->value,
            'moneda' => $c->moneda,
            'total_minor' => $c->total_minor,
            'ajustes_minor' => $ajustes,
            'total_con_ajustes_minor' => $c->total_minor + $ajustes,
            'cerrado_por' => $c->cerrado_por_nombre,
            'cerrado_en' => $c->cerrado_en->toIso8601String(),
            'pagado_por' => $c->pagado_por_nombre,
            'pagado_en' => $c->pagado_en?->toIso8601String(),
            'referencia_pago' => $c->referencia_pago,
        ];
    }
}
