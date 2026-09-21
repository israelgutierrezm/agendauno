<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Asistencia\EstadoAsistencia;
use App\Modules\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\TipoPersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Cohortes de retención y embudo de conversión (Etapa 2): agrupa a los alumnos por
 * el mes de alta (cohorte) y mide, mes a mes, qué proporción sigue asistiendo
 * (triángulo de retención). El embudo mide, sobre esas altas recientes, cuántos
 * compraron y cuántos se activaron (asistieron). Todo derivado de los datos del
 * tenant (altas, asistencias, órdenes pagadas); consultas acotadas a la ventana.
 */
class ReporteCohortesTenantController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'meses' => ['nullable', 'integer', 'min:2', 'max:12'],
        ]);
        $n = (int) ($validado['meses'] ?? 6);

        $zona = (string) (SucursalTenant::query()->value('zona_horaria') ?? config('app.timezone', 'UTC'));
        $mesActual = CarbonImmutable::now($zona)->startOfMonth();
        $primerMes = $mesActual->subMonths($n - 1);

        // Meses de las cohortes (más antiguo primero).
        $mesesCohorte = [];
        for ($i = 0; $i < $n; $i++) {
            $mesesCohorte[] = $primerMes->addMonths($i);
        }

        // Alumnos dados de alta en la ventana (la población de las cohortes).
        $miembros = PersonaTenant::query()
            ->where('tipo', TipoPersonaTenant::Miembro->value)
            ->where('archivado', false)
            ->where('created_at', '>=', $primerMes->utc())
            ->get(['id', 'created_at']);

        // Meses con asistencia (presente) por persona, dentro de la ventana.
        $mesesActivos = $this->mesesActivosPorPersona($primerMes, $zona);
        // Personas que compraron (órden pagada) — para el embudo.
        $compradores = $this->compradores();

        // Agrupa miembros por mes de alta.
        $porCohorte = $miembros->groupBy(fn (PersonaTenant $p): string => CarbonImmutable::instance($p->created_at)->setTimezone($zona)->format('Y-m'));

        $cohortes = [];
        foreach ($mesesCohorte as $mes) {
            $clave = $mes->format('Y-m');
            /** @var Collection<int, PersonaTenant> $integrantes */
            $integrantes = $porCohorte->get($clave, collect());
            $tamano = $integrantes->count();

            $retencion = [];
            for ($k = 0; $k < $n; $k++) {
                $objetivo = $mes->addMonths($k);
                if ($objetivo->gt($mesActual)) {
                    $retencion[] = null; // mes futuro: aún no medible

                    continue;
                }
                if ($tamano === 0) {
                    $retencion[] = null;

                    continue;
                }
                $objetivoClave = $objetivo->format('Y-m');
                $activos = $integrantes->filter(fn (PersonaTenant $p): bool => in_array($objetivoClave, $mesesActivos[$p->getKey()] ?? [], true))->count();
                $retencion[] = (int) round($activos / $tamano * 100);
            }

            $cohortes[] = ['mes' => $clave, 'tamano' => $tamano, 'retencion' => $retencion];
        }

        return response()->json(['data' => [
            'meses' => $n,
            'cohortes' => $cohortes,
            'conversion' => $this->embudo($miembros, $mesesActivos, $compradores),
        ]]);
    }

    /**
     * Embudo sobre las altas de la ventana: registrados → compraron → activos.
     *
     * @param  Collection<int, PersonaTenant>  $miembros
     * @param  array<int, list<string>>  $mesesActivos
     * @param  array<int, bool>  $compradores
     * @return array<string, int>
     */
    private function embudo(Collection $miembros, array $mesesActivos, array $compradores): array
    {
        $registrados = $miembros->count();
        $compraron = $miembros->filter(fn (PersonaTenant $p): bool => isset($compradores[$p->getKey()]))->count();
        $activos = $miembros->filter(fn (PersonaTenant $p): bool => ($mesesActivos[$p->getKey()] ?? []) !== [])->count();

        return [
            'registrados' => $registrados,
            'compraron' => $compraron,
            'activos' => $activos,
        ];
    }

    /**
     * Meses (Y-m, en la zona) con asistencia `presente` por persona, desde `$desde`.
     *
     * @return array<int, list<string>>
     */
    private function mesesActivosPorPersona(CarbonImmutable $desde, string $zona): array
    {
        $filas = ReservaTenant::query()
            ->join('asistencias', 'asistencias.reserva_id', '=', 'reservas.id')
            ->where('asistencias.estado', EstadoAsistencia::Presente->value)
            ->where('asistencias.registrada_en', '>=', $desde->utc())
            ->get(['reservas.persona_id', 'asistencias.registrada_en']);

        $mapa = [];
        foreach ($filas as $fila) {
            $pid = (int) $fila->getAttribute('persona_id');
            $mes = CarbonImmutable::parse((string) $fila->getAttribute('registrada_en'))->setTimezone($zona)->format('Y-m');
            $mapa[$pid][$mes] = true;
        }

        return array_map(static fn (array $meses): array => array_keys($meses), $mapa);
    }

    /**
     * Personas con al menos una orden pagada (para el embudo de conversión).
     *
     * @return array<int, bool>
     */
    private function compradores(): array
    {
        return OrdenTenant::query()
            ->where('estado', EstadoOrden::Pagada->value)
            ->whereNotNull('persona_id')
            ->distinct()
            ->pluck('persona_id')
            ->mapWithKeys(static fn ($id): array => [(int) $id => true])
            ->all();
    }
}
