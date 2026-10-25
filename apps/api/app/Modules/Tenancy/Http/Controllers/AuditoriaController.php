<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\BitacoraTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AuditoriaTenant;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bitacora de auditoria del estudio (append-only): quién hizo qué, sobre qué y cuándo.
 * Solo lectura y gateada por permiso. Filtra por fechas, persona del equipo,
 * categoría, acción, entidad o texto; pagina y se descarga en CSV. Opera sobre la BD
 * del estudio resuelto.
 */
class AuditoriaController
{
    private const POR_PAGINA = 50;

    private const MAX_CSV = 5000;

    public function __construct(
        private readonly BitacoraTenant $bitacora,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    public function index(Request $request): JsonResponse|Response
    {
        // La bitácora es de todo el negocio (cambios de cualquier sede, del sistema y de
        // las pasarelas): quien está acotado a unas sedes (R19, ADR 0098) no la ve.
        abort_if(app(ResolverAccesoTenant::class)->deLaSolicitud($request) !== null, 403, 'La bitácora es de todo el negocio: tu rol solo ve tus sucursales.');
        $filtros = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            // Días del negocio; el final no puede quedar antes que el inicio.
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'usuario' => ['nullable', 'string', 'max:40'],
            'categoria' => ['nullable', Rule::in(array_keys(BitacoraTenant::CATEGORIAS))],
            'accion' => ['nullable', 'string', 'max:100'],
            'entidad_tipo' => ['nullable', 'string', 'max:60'],
            'entidad_id' => ['nullable', 'string', 'max:60'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'formato' => ['nullable', Rule::in(['json', 'csv'])],
        ]);

        $consulta = $this->bitacora->consulta($filtros);

        if (($filtros['formato'] ?? 'json') === 'csv') {
            return $this->csv($consulta->limit(self::MAX_CSV)->get()->all());
        }

        $pagina = $consulta->paginate((int) ($filtros['per_page'] ?? self::POR_PAGINA), ['*'], 'page', (int) ($filtros['page'] ?? 1));

        return response()->json([
            'data' => collect($pagina->items())->map(fn (AuditoriaTenant $a): array => $this->presentar($a))->all(),
            'meta' => [
                'total' => $pagina->total(),
                'page' => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'actores' => $this->bitacora->actores(),
                'categorias' => array_keys(BitacoraTenant::CATEGORIAS),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(AuditoriaTenant $a): array
    {
        return [
            'id' => $a->ulid,
            'actor' => $a->actor_nombre,
            'accion' => $a->accion,
            'categoria' => BitacoraTenant::categoria($a->accion),
            'descripcion' => BitacoraTenant::descripcion($a),
            'entidad_tipo' => $a->entidad_tipo,
            'entidad_id' => $a->entidad_id,
            'motivo' => $a->motivo,
            'antes' => $a->antes,
            'despues' => $a->despues,
            'ip' => $a->ip,
            'correlation_id' => $a->correlation_id,
            'fecha' => $a->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<AuditoriaTenant>  $asientos
     */
    private function csv(array $asientos): Response
    {
        $zona = (string) ($this->gestor->actual()?->zona_horaria ?: 'America/Mexico_City');
        $lineas = ['Fecha,Quién,Qué pasó,Acción,Motivo,IP'];
        foreach ($asientos as $a) {
            $lineas[] = implode(',', array_map(Csv::celda(...), [
                $a->created_at !== null ? CarbonImmutable::parse($a->created_at)->setTimezone($zona)->format('Y-m-d H:i') : '',
                (string) ($a->actor_nombre ?? 'Sistema'),
                BitacoraTenant::descripcion($a),
                $a->accion,
                (string) ($a->motivo ?? ''),
                (string) ($a->ip ?? ''),
            ]));
        }

        return response("\u{FEFF}".implode("\n", $lineas)."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="bitacora.csv"',
        ]);
    }
}
