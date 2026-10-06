<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\MovimientosDePagoTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tendencias del dinero (Etapa 2): serie por día/semana/mes de un periodo en la moneda
 * del negocio, con las mismas reglas que el reporte principal y el corte de caja
 * ({@see MovimientosDePagoTenant}): lo VENDIDO cuenta por la fecha de la compra (y
 * cuántas ventas); lo COBRADO, por la fecha del cobro (con mostrador); lo DEVUELTO,
 * por la fecha de la devolución, también parciales; el NETO es cobrado − devuelto.
 * Nunca suma monedas: lo que haya en otras (de antes de trabajar con una sola) va
 * aparte, en `otras_monedas`. Quien está acotado a sucursales solo ve lo de las suyas
 * (serie, totales, desglose por producto y exportación). Los buckets se rellenan
 * (incluye ceros) para una línea continua. Montos en minor. `?formato=csv` exporta.
 */
class ReporteTendenciasTenantController
{
    /**
     * @var list<string>
     */
    private const AGRUPACIONES = ['dia', 'semana', 'mes'];

    /** Cifras de dinero de un bucket o de un periodo. */
    private const CEROS = ['ventas' => 0, 'ventas_minor' => 0, 'cobrado_minor' => 0, 'devuelto_minor' => 0, 'neto_minor' => 0];

    public function __invoke(
        Request $request,
        MovimientosDePagoTenant $movimientos,
        ResolverAccesoTenant $acceso,
        RegionNegocioTenant $region,
    ): Response {
        $validado = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'agrupacion' => ['nullable', 'string', 'in:'.implode(',', self::AGRUPACIONES)],
        ]);
        $agrupacion = (string) ($validado['agrupacion'] ?? 'dia');
        $desde = CarbonImmutable::parse($validado['desde'])->toDateString();
        $hasta = CarbonImmutable::parse($validado['hasta'])->toDateString();

        $zona = app(FechasNegocioTenant::class)->zona();
        $inicioZona = CarbonImmutable::parse($desde.' 00:00:00', $zona);
        $finZona = CarbonImmutable::parse($hasta.' 00:00:00', $zona);
        $sedes = $acceso->deLaSolicitud($request);
        $moneda = $region->moneda();

        // Día por día y por moneda; cada moneda en sus propios buckets.
        $diario = $movimientos->diario($desde, $hasta, $sedes);
        $series = [];
        foreach ($diario as $codigo => $dias) {
            $series[$codigo] = $this->bucketsVacios($inicioZona, $finZona, $agrupacion);
            foreach ($dias as $dia => $cifras) {
                $clave = $this->clave(CarbonImmutable::parse($dia, $zona), $agrupacion);
                if (! isset($series[$codigo][$clave])) {
                    continue;
                }
                foreach (['ventas', 'ventas_minor', 'cobrado_minor', 'devuelto_minor'] as $campo) {
                    $series[$codigo][$clave][$campo] += $cifras[$campo];
                }
                $series[$codigo][$clave]['neto_minor'] = $series[$codigo][$clave]['cobrado_minor'] - $series[$codigo][$clave]['devuelto_minor'];
            }
        }
        $serie = array_values($series[$moneda] ?? $this->bucketsVacios($inicioZona, $finZona, $agrupacion));
        $totales = $this->sumar($serie);

        $otras = [];
        foreach ($series as $codigo => $buckets) {
            if ($codigo === $moneda) {
                continue;
            }
            $suma = $this->sumar(array_values($buckets));
            unset($suma['ventas'], $suma['ticket_promedio_minor']);
            $otras[] = ['moneda' => $codigo, ...$suma];
        }

        if ((string) $request->query('formato') === 'csv') {
            return $this->exportarCsv($series, $moneda);
        }

        return response()->json(['data' => [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'agrupacion' => $agrupacion,
            'moneda' => $moneda,
            'serie' => $serie,
            'por_producto' => $this->porProducto(CarbonImmutable::parse($desde, $zona)->startOfDay()->utc(), CarbonImmutable::parse($hasta, $zona)->endOfDay()->utc(), $moneda, $sedes),
            'totales' => $totales,
            'otras_monedas' => $otras,
        ]]);
    }

    /**
     * Suma de una serie (con el ticket promedio de lo vendido).
     *
     * @param  list<array<string, int|string>>  $serie
     * @return array{ventas: int, ventas_minor: int, cobrado_minor: int, devuelto_minor: int, neto_minor: int, ticket_promedio_minor: int|null}
     */
    private function sumar(array $serie): array
    {
        $total = self::CEROS;
        foreach ($serie as $fila) {
            foreach (array_keys(self::CEROS) as $campo) {
                $total[$campo] += (int) $fila[$campo];
            }
        }

        return [...$total, 'ticket_promedio_minor' => $total['ventas'] > 0 ? (int) round($total['ventas_minor'] / $total['ventas']) : null];
    }

    /**
     * Buckets vacíos (clave → fila) alineados a la granularidad, a lo largo del periodo.
     *
     * @return array<string, array{fecha: string, ventas: int, ventas_minor: int, cobrado_minor: int, devuelto_minor: int, neto_minor: int}>
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
            $buckets[$this->clave($cursor, $agrupacion)] = ['fecha' => $cursor->toDateString(), ...self::CEROS];
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
     * Lo vendido por producto (subtotal de línea) en la moneda del negocio: compras
     * no canceladas, por la fecha de la compra, de las sedes que ve quien consulta.
     *
     * @param  list<int>|null  $sedes
     * @return list<array{producto: string, ventas_minor: int, unidades: int}>
     */
    private function porProducto(CarbonImmutable $inicio, CarbonImmutable $fin, string $moneda, ?array $sedes): array
    {
        $filas = LineaOrdenTenant::query()
            ->join('ordenes', 'ordenes.id', '=', 'lineas_orden.orden_id')
            ->where('ordenes.estado', '!=', EstadoOrden::Cancelada->value)
            ->where('ordenes.moneda', $moneda)
            ->when($sedes !== null, fn ($q) => $q->whereIn('ordenes.sucursal_id', $sedes))
            ->whereBetween('ordenes.created_at', [$inicio, $fin])
            ->groupBy('lineas_orden.producto_comercial_id')
            ->selectRaw('lineas_orden.producto_comercial_id as pid, SUM(lineas_orden.subtotal_minor) as vendido, SUM(lineas_orden.cantidad) as unidades')
            ->get();

        $nombres = ProductoTenant::query()
            ->whereIn('id', $filas->pluck('pid')->all())
            ->pluck('nombre', 'id');

        return $filas
            ->map(static fn ($f): array => [
                'producto' => (string) ($nombres->get((int) $f->getAttribute('pid')) ?? '—'),
                'ventas_minor' => (int) $f->getAttribute('vendido'),
                'unidades' => (int) $f->getAttribute('unidades'),
            ])
            ->sortByDesc('ventas_minor')
            ->values()
            ->all();
    }

    /**
     * La serie de la moneda del negocio y, si hubo, la de otras (cada fila con su
     * moneda; nunca sumadas).
     *
     * @param  array<string, array<string, array{fecha: string, ventas: int, ventas_minor: int, cobrado_minor: int, devuelto_minor: int, neto_minor: int}>>  $series
     */
    private function exportarCsv(array $series, string $moneda): Response
    {
        $dinero = static fn (int $minor): string => number_format($minor / 100, 2, '.', '');
        $lineas = ['Fecha,Moneda,Ventas,Vendido,Cobrado,Devuelto,Neto'];
        $codigos = array_keys($series);
        usort($codigos, static fn (string $a, string $b): int => [$a !== $moneda, $a] <=> [$b !== $moneda, $b]);
        foreach ($codigos as $codigo) {
            foreach ($series[$codigo] as $fila) {
                $lineas[] = implode(',', [
                    $fila['fecha'], $codigo, (string) $fila['ventas'], $dinero($fila['ventas_minor']),
                    $dinero($fila['cobrado_minor']), $dinero($fila['devuelto_minor']), $dinero($fila['neto_minor']),
                ]);
            }
        }

        return response("\u{FEFF}".implode("\n", $lineas)."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="tendencias-dinero.csv"',
        ]);
    }
}
