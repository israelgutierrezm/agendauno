<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcancePersonaTenant;
use App\Modules\Tenancy\Application\GestionarDunningTenant;
use App\Modules\Tenancy\EstadoDunning;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\ProcesoDunningTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dunning tenant-local (R10): registrar fallos de cobro de membresías, regularizar
 * y consultar los procesos de morosidad abiertos (morosos). Opera sobre la BD del
 * estudio resuelto; gateado por permisos tenant-local.
 */
class DunningTenantController
{
    public function __construct(private readonly GestionarDunningTenant $dunning) {}

    /**
     * Procesos de morosidad abiertos (en mora o suspendidos), por páginas; se buscan por
     * la persona. El total sin filtros da el indicador.
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in([EstadoDunning::EnMora->value, EstadoDunning::Suspendido->value])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        // Quien está acotado a sedes (R19) ve solo la mora de sus clientes.
        $base = ProcesoDunningTenant::query()
            ->whereIn('estado', [EstadoDunning::EnMora->value, EstadoDunning::Suspendido->value])
            ->whereHas('acuerdo', fn ($a) => app(AlcancePersonaTenant::class)->acotada($a, $request));
        $total = (clone $base)->count();
        $buscar = trim((string) ($filtros['q'] ?? ''));
        $pagina = $base
            ->when($buscar !== '', fn ($q) => $q->whereHas('acuerdo.persona', fn ($p) => $p->buscar($buscar)))
            ->when(isset($filtros['estado']), fn ($q) => $q->where('estado', $filtros['estado']))
            ->with('acuerdo.persona')
            ->orderBy('gracia_hasta')
            ->orderBy('id')
            ->paginate((int) ($filtros['per_page'] ?? 25), ['*'], 'page', (int) ($filtros['page'] ?? 1));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (ProcesoDunningTenant $p): array => $this->presentar($p))->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'resumen' => ['total' => $total],
            ],
        ]);
    }

    /**
     * Registra un fallo de cobro de la membresía (abre/avanza el dunning). A mano no
     * lleva clave de motivo: lo que escribió el equipo queda como detalle.
     */
    public function registrarFallo(Request $request): JsonResponse
    {
        $acuerdo = $this->acuerdoDe($request);
        $validado = $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);

        $proceso = $this->dunning->registrarFallo($acuerdo, null, isset($validado['motivo']) ? (string) $validado['motivo'] : null);

        return response()->json(['data' => $this->presentar($proceso->load('acuerdo.persona'))], 201);
    }

    /**
     * Registra que el pago se resolvió (cierra el dunning y reactiva el acuerdo).
     */
    public function regularizar(Request $request): JsonResponse
    {
        $acuerdo = $this->acuerdoDe($request);

        $proceso = $this->dunning->registrarPago($acuerdo);

        return response()->json([
            'data' => $proceso !== null ? $this->presentar($proceso->load('acuerdo.persona')) : null,
        ]);
    }

    private function acuerdoDe(Request $request): AcuerdoTenant
    {
        $acuerdo = AcuerdoTenant::query()->where('ulid', (string) $request->route('acuerdo'))->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirAcuerdo($request, $acuerdo);

        return $acuerdo;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(ProcesoDunningTenant $proceso): array
    {
        $acuerdo = $proceso->acuerdo;
        $persona = $acuerdo?->persona;

        return [
            'id' => $proceso->ulid,
            'acuerdo' => $acuerdo?->ulid,
            'persona' => $persona !== null ? ['id' => $persona->ulid, 'nombre' => $persona->nombreCompleto()] : null,
            'estado' => $proceso->estado->value,
            'intentos' => $proceso->intentos,
            'gracia_hasta' => $proceso->gracia_hasta->toIso8601String(),
            'proximo_intento_en' => $proceso->proximo_intento_en?->toIso8601String(),
            'suspendido_en' => $proceso->suspendido_en?->toIso8601String(),
            // La frase que se le mandó al alumno (en segunda persona): el panel no la muestra.
            'ultimo_motivo' => $proceso->ultimo_motivo,
            // Por qué no se cobró, para quien administra: la clave (null si se registró a
            // mano) y el detalle (el motivo del banco o lo que escribió el equipo).
            'motivo_clave' => $proceso->ultimo_motivo_clave?->value,
            'motivo_detalle' => $proceso->ultimo_motivo_detalle,
        ];
    }
}
