<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcanceClientesTenant;
use App\Modules\Tenancy\Application\ModalidadNegocioTenant;
use App\Modules\Tenancy\Application\RadarRenovacionesTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Application\SegmentosRetencionTenant;
use App\Modules\Tenancy\Application\SeguimientoTenant;
use App\Modules\Tenancy\Automatizacion\EstadoTarea;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\TareaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Radar de retención (Etapa 2): alumnos con membresía POR VENCER (próximos días) o
 * VENCIDA hace poco (recuperable), con señales de churn (última asistencia) para que
 * el estudio actúe antes de perderlos (la regla vive en RadarRenovacionesTenant).
 * `?formato=csv` exporta la lista para campañas de renovación, con los mismos
 * filtros de estado y búsqueda que la tabla.
 */
class RetencionTenantController
{
    public function __construct(
        private readonly ResolverAccesoTenant $acceso,
        private readonly RadarRenovacionesTenant $radar,
        private readonly SegmentosRetencionTenant $segmentos,
    ) {}

    /**
     * Retención por segmentos (ADR 0159): cada grupo con cuántos son y cuántos aceptan
     * promociones (a quienes llega una difusión).
     */
    public function segmentos(Request $request): JsonResponse
    {
        $this->exigirNegocioCompleto($request);
        $citas = app(ModalidadNegocioTenant::class)->esCitas();
        $datos = [];
        foreach ($this->segmentos->disponibles() as $clave) {
            $ids = array_column($this->segmentos->personas($clave), 'persona_id');
            $motivo = SegmentosRetencionTenant::SEGMENTOS[$clave];
            $datos[] = [
                'clave' => $clave,
                'etiqueta' => $motivo->etiqueta($citas),
                'total' => count($ids),
                'con_promociones' => $ids === [] ? 0 : PersonaTenant::query()->whereIn('id', $ids)->where('recibe_promociones', true)->count(),
            ];
        }

        return response()->json(['data' => $datos]);
    }

    /** Las personas de un segmento, por páginas y con búsqueda, con el porqué de cada una. */
    public function segmento(Request $request): JsonResponse
    {
        $this->exigirNegocioCompleto($request);
        $clave = $this->clave($request);
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $filas = collect($this->segmentos->personas($clave))->keyBy('persona_id');
        $motivo = SegmentosRetencionTenant::SEGMENTOS[$clave];
        $abiertas = TareaTenant::query()->whereIn('persona_id', $filas->keys())->where('motivo', $motivo->value)
            ->where('estado', EstadoTarea::Pendiente->value)->pluck('persona_id')->map(static fn (mixed $id): int => (int) $id)->flip();
        $buscar = trim((string) ($filtros['q'] ?? ''));
        $consulta = PersonaTenant::query()->whereIn('id', $filas->keys())
            ->when($buscar !== '', fn ($q) => $q->buscar($buscar));
        $orden = $filas->keys()->flip();
        $personas = $consulta->get()->sortBy(static fn (PersonaTenant $p): int => (int) $orden->get($p->id))->values();
        $porPagina = (int) ($filtros['per_page'] ?? 25);
        $ultima = max(1, (int) ceil($personas->count() / $porPagina));
        $pagina = min(max(1, (int) ($filtros['page'] ?? 1)), $ultima);

        return response()->json([
            'data' => $personas->slice(($pagina - 1) * $porPagina, $porPagina)->map(fn (PersonaTenant $p): array => [
                'id' => $p->ulid,
                'nombre' => $p->nombreCompleto(),
                'detalle' => $filas->get($p->id)['detalle'] ?? '',
                'recibe_promociones' => (bool) $p->recibe_promociones,
                'seguimiento_abierto' => $abiertas->has($p->id),
            ])->values()->all(),
            'meta' => ['total' => $personas->count(), 'page' => $pagina, 'per_page' => $porPagina, 'ultima_pagina' => $ultima],
        ]);
    }

    /**
     * Una tarea de seguimiento para cada persona del segmento, con su mensaje sugerido.
     * Sin repetir a quien ya tiene una abierta o reciente por lo mismo, ni a quien no
     * desea promociones si el motivo es comercial (las reglas del seguimiento diario).
     */
    public function seguimientos(Request $request, SeguimientoTenant $seguimiento): JsonResponse
    {
        $this->exigirNegocioCompleto($request);
        $clave = $this->clave($request);
        $motivo = SegmentosRetencionTenant::SEGMENTOS[$clave];
        $creadas = 0;
        $filas = $this->segmentos->personas($clave);
        foreach ($filas as $fila) {
            if ($seguimiento->crearPorSegmento($fila['persona_id'], $motivo, $fila['dato'])) {
                $creadas++;
            }
        }

        return response()->json(['data' => ['creadas' => $creadas, 'omitidas' => count($filas) - $creadas]], 201);
    }

    private function clave(Request $request): string
    {
        $clave = (string) $request->route('segmento');
        abort_unless(in_array($clave, $this->segmentos->disponibles(), true), 404);

        return $clave;
    }

    /** Los segmentos son de todo el negocio: no para quien imparte (ve solo a sus clientes). */
    private function exigirNegocioCompleto(Request $request): void
    {
        $actor = $request->attributes->get('usuario_tenant');
        abort_if($actor instanceof Usuario && app(AlcanceClientesTenant::class)->esAcotado($actor), 403);
    }

    public function porVencer(Request $request): Response
    {
        $dias = min(max((int) $request->query('dias', (string) $this->radar->diasPorDefecto()), 1), 90);

        // Alcance por sucursal (R19): el staff acotado solo ve el radar de SUS sedes.
        $actor = $request->attributes->get('usuario_tenant');
        // Números de todo el negocio: no para quien imparte (ve solo a sus clientes).
        abort_if($actor instanceof Usuario && app(AlcanceClientesTenant::class)->esAcotado($actor), 403);
        $permitidas = $actor instanceof Usuario ? $this->acceso->sucursalesPermitidas($actor) : null;

        return $this->responder($this->radar->miembros($dias, $permitidas), $dias, $request);
    }

    /**
     * @param  list<array<string, mixed>>  $miembros
     */
    private function responder(array $miembros, int $dias, Request $request): Response
    {
        // El estado y la búsqueda valen igual en pantalla y en el CSV: se descarga lo
        // que se está viendo (todas las páginas). El resumen cuenta a todos.
        $filtros = $request->validate([
            'estado' => ['nullable', 'in:por_vencer,vencida'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $buscar = mb_strtolower(trim((string) ($filtros['q'] ?? '')));
        $lista = array_values(array_filter($miembros, static fn (array $m): bool => (($filtros['estado'] ?? null) === null || $m['estado'] === $filtros['estado'])
            && ($buscar === '' || str_contains(mb_strtolower((string) $m['nombre_completo']), $buscar) || str_contains(mb_strtolower((string) ($m['email'] ?? '')), $buscar))));

        if ((string) $request->query('formato') === 'csv') {
            return $this->exportarCsv($lista);
        }

        $porVencer = count(array_filter($miembros, static fn (array $m): bool => $m['estado'] === 'por_vencer'));
        $vencidas = count($miembros) - $porVencer;

        $porPagina = (int) ($filtros['per_page'] ?? 25);
        $ultima = max(1, (int) ceil(count($lista) / $porPagina));
        $pagina = min(max(1, (int) ($filtros['page'] ?? 1)), $ultima);

        return response()->json(['data' => [
            'resumen' => ['por_vencer' => $porVencer, 'vencidas' => $vencidas, 'dias' => $dias],
            'miembros' => array_slice($lista, ($pagina - 1) * $porPagina, $porPagina),
            'meta' => ['total' => count($lista), 'page' => $pagina, 'per_page' => $porPagina, 'ultima_pagina' => $ultima],
        ]]);
    }

    /**
     * @param  list<array<string, mixed>>  $miembros
     */
    private function exportarCsv(array $miembros): Response
    {
        $lineas = ['Alumno,Correo,Estado,Vence,Dias restantes,Ultima asistencia'];
        foreach ($miembros as $m) {
            $lineas[] = implode(',', array_map($this->escaparCsv(...), [
                (string) $m['nombre_completo'],
                (string) ($m['email'] ?? ''),
                $m['estado'] === 'por_vencer' ? 'Por vencer' : 'Vencida',
                (string) $m['vence'],
                (string) $m['dias_restantes'],
                (string) ($m['ultima_asistencia'] ?? ''),
            ]));
        }

        return response(implode("\n", $lineas)."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="retencion-por-vencer.csv"',
        ]);
    }

    private function escaparCsv(string $valor): string
    {
        return str_contains($valor, ',') || str_contains($valor, '"') || str_contains($valor, "\n")
            ? '"'.str_replace('"', '""', $valor).'"'
            : $valor;
    }
}
