<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tendencias de ingresos (Etapa 2): serie temporal de ingresos y órdenes pagadas por
 * día/semana/mes en un periodo, más el desglose por producto. Los buckets se rellenan
 * (incluye ceros) para una línea de tendencia continua. Montos en minor. El periodo se
 * interpreta en la zona de una sucursal. `?formato=csv` exporta la serie.
 */
class ReporteTendenciasTenantController
{
    /**
     * @var list<string>
     */
    private const AGRUPACIONES = ['dia', 'semana', 'mes'];

    public function __invoke(Request $request): Response
    {
        $validado = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'agrupacion' => ['nullable', 'string', 'in:'.implode(',', self::AGRUPACIONES)],
        ]);
        $agrupacion = (string) ($validado['agrupacion'] ?? 'dia');

        $zona = (string) (SucursalTenant::query()->value('zona_horaria') ?? config('app.timezone', 'UTC'));
        $inicioZona = CarbonImmutable::parse($validado['desde'].' 00:00:00', $zona);
        $finZona = CarbonImmutable::parse($validado['hasta'].' 00:00:00', $zona);
        // Ventana en UTC (acota el query sin cortar por desfase de zona).
        $inicio = $inicioZona->utc();
        $fin = $finZona->addDay()->utc();

        // Buckets vacíos a lo largo del periodo (se rellenan con las órdenes).
        $buckets = $this->bucketsVacios($inicioZona, $finZona, $agrupacion);

        $ordenesQuery = OrdenTenant::query()
            ->where('estado', EstadoOrden::Pagada->value)
            ->whereBetween('created_at', [$inicio, $fin]);
        $moneda = (string) ((clone $ordenesQuery)->value('moneda') ?? 'MXN');
        $ordenes = $ordenesQuery->get(['created_at', 'total_minor']);

        $ingresosTotal = 0;
        foreach ($ordenes as $orden) {
            $creada = $orden->created_at;
            if ($creada === null) {
                continue;
            }
            $clave = $this->clave(CarbonImmutable::instance($creada)->setTimezone($zona), $agrupacion);
            if (! isset($buckets[$clave])) {
                continue;
            }
            $buckets[$clave]['ingresos_minor'] += (int) $orden->total_minor;
            $buckets[$clave]['ordenes']++;
            $ingresosTotal += (int) $orden->total_minor;
        }

        $serie = array_values($buckets);
        $ordenesTotal = array_sum(array_column($serie, 'ordenes'));

        if ((string) $request->query('formato') === 'csv') {
            return $this->exportarCsv($serie);
        }

        return response()->json(['data' => [
            'periodo' => ['desde' => $validado['desde'], 'hasta' => $validado['hasta']],
            'agrupacion' => $agrupacion,
            'moneda' => $moneda,
            'serie' => $serie,
            'por_producto' => $this->porProducto($inicio, $fin),
            'totales' => [
                'ingresos_minor' => $ingresosTotal,
                'ordenes' => $ordenesTotal,
                'ticket_promedio_minor' => $ordenesTotal > 0 ? (int) round($ingresosTotal / $ordenesTotal) : null,
            ],
        ]]);
    }

    /**
     * Buckets vacíos (clave → fila) alineados a la granularidad, a lo largo del periodo.
     *
     * @return array<string, array{fecha: string, ingresos_minor: int, ordenes: int}>
     */
    private function bucketsVacios(CarbonImmutable $inicioZona, CarbonImmutable $finZona, string $agrupacion): array
    {
        $cursor = match ($agrupacion) {
            'semana' => $inicioZona->startOfWeek(),
            'mes' => $inicioZona->startOfMonth(),
            default => $inicioZona->startOfDay(),
        };

        $buckets = [];
        while ($cursor->lte($finZona)) {
            $buckets[$this->clave($cursor, $agrupacion)] = [
                'fecha' => $cursor->toDateString(),
                'ingresos_minor' => 0,
                'ordenes' => 0,
            ];
            $cursor = match ($agrupacion) {
                'semana' => $cursor->addWeek(),
                'mes' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $buckets;
    }

    /**
     * Clave de bucket de una fecha (ya en la zona del estudio) según la granularidad.
     */
    private function clave(CarbonImmutable $fechaZona, string $agrupacion): string
    {
        return match ($agrupacion) {
            'semana' => $fechaZona->startOfWeek()->toDateString(),
            'mes' => $fechaZona->format('Y-m'),
            default => $fechaZona->toDateString(),
        };
    }

    /**
     * Desglose de ingresos por producto (por subtotal de línea) en el periodo.
     *
     * @return list<array{producto: string, ingresos_minor: int, unidades: int}>
     */
    private function porProducto(CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        $filas = LineaOrdenTenant::query()
            ->join('ordenes', 'ordenes.id', '=', 'lineas_orden.orden_id')
            ->where('ordenes.estado', EstadoOrden::Pagada->value)
            ->whereBetween('ordenes.created_at', [$inicio, $fin])
            ->groupBy('lineas_orden.producto_comercial_id')
            ->selectRaw('lineas_orden.producto_comercial_id as pid, SUM(lineas_orden.subtotal_minor) as ingresos, SUM(lineas_orden.cantidad) as unidades')
            ->get();

        $nombres = ProductoTenant::query()
            ->whereIn('id', $filas->pluck('pid')->all())
            ->pluck('nombre', 'id');

        return $filas
            ->map(static fn ($f): array => [
                'producto' => (string) ($nombres->get((int) $f->getAttribute('pid')) ?? '—'),
                'ingresos_minor' => (int) $f->getAttribute('ingresos'),
                'unidades' => (int) $f->getAttribute('unidades'),
            ])
            ->sortByDesc('ingresos_minor')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{fecha: string, ingresos_minor: int, ordenes: int}>  $serie
     */
    private function exportarCsv(array $serie): Response
    {
        $lineas = ['Fecha,Ingresos,Ordenes'];
        foreach ($serie as $fila) {
            $lineas[] = implode(',', [
                $fila['fecha'],
                number_format($fila['ingresos_minor'] / 100, 2, '.', ''),
                (string) $fila['ordenes'],
            ]);
        }

        return response(implode("\n", $lineas)."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="tendencias-ingresos.csv"',
        ]);
    }
}
