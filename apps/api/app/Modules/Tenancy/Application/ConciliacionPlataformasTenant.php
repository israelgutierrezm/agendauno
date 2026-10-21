<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\CheckinTenant;
use App\Modules\Tenancy\Models\ConciliacionPlataformaTenant;
use App\Modules\Tenancy\Models\PagoPlataformaTenant;
use App\Modules\Tenancy\Models\TarifaPlataformaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Conciliación de lo que pagan Wellhub y TotalPass (ADR 0185). Ninguna publica una API
 * de pagos: lo esperado son las visitas validadas del mes por lo que paga cada visita
 * (la tarifa que capturó el negocio, con fecha de vigencia); lo recibido, el reporte de
 * pagos del portal de la plataforma que el negocio importa (CSV). Cada pago se empareja
 * con su visita por la referencia del check-in o, sin ella, por el día.
 *
 * Una visita en $0 (primera visita gratis, tope mensual por persona) sale como monto
 * distinto: el negocio decide si la reclama.
 */
class ConciliacionPlataformasTenant
{
    /** Más renglones que esto no se importan de una vez. */
    private const MAX_LINEAS = 20000;

    public function __construct(
        private readonly FechasNegocioTenant $fechas,
        private readonly ParametrosTenant $parametros,
    ) {}

    /** La tarifa vigente un día, o null si el negocio no capturó una. */
    public function tarifaEn(string $proveedor, string $fecha): ?TarifaPlataformaTenant
    {
        return TarifaPlataformaTenant::query()
            ->where('proveedor', $proveedor)
            ->whereDate('vigente_desde', '<=', $fecha)
            ->orderByDesc('vigente_desde')
            ->first();
    }

    public function pactarTarifa(string $proveedor, int $montoMinor, string $desde): TarifaPlataformaTenant
    {
        return TarifaPlataformaTenant::query()->updateOrCreate(
            ['proveedor' => $proveedor, 'vigente_desde' => $desde],
            ['monto_minor' => $montoMinor, 'moneda' => $this->parametros->moneda()],
        );
    }

    /**
     * Importa el reporte de pagos de un mes (reemplaza el anterior del mismo mes).
     * Columnas por su encabezado: fecha, monto y, si viene, la referencia del check-in.
     */
    public function importar(string $proveedor, string $periodo, string $contenido, ?string $archivo, ?Usuario $actor = null): ConciliacionPlataformaTenant
    {
        $filas = $this->leer($contenido);

        return DB::connection('tenant')->transaction(function () use ($proveedor, $periodo, $filas, $archivo, $actor): ConciliacionPlataformaTenant {
            ConciliacionPlataformaTenant::query()->where('proveedor', $proveedor)->where('periodo', $periodo)->delete();
            $conciliacion = ConciliacionPlataformaTenant::query()->create([
                'proveedor' => $proveedor,
                'periodo' => $periodo,
                'archivo' => $archivo === null ? null : mb_substr($archivo, 0, 160),
                'lineas' => count($filas),
                'recibido_minor' => array_sum(array_column($filas, 'monto_minor')),
                'moneda' => $this->parametros->moneda(),
                'importado_por' => $actor?->getKey(),
            ]);

            // Las visitas del mes aún sin pago, para emparejarlas.
            $visitas = $this->visitas($proveedor, $periodo);
            $porReferencia = $visitas->filter(fn (CheckinTenant $c): bool => (string) $c->referencia_externa !== '')
                ->keyBy(fn (CheckinTenant $c): string => (string) $c->referencia_externa);
            $porDia = $visitas->groupBy(fn (CheckinTenant $c): string => $this->fechas->fecha($c->registrado_en))
                ->map(fn (Collection $c): array => $c->modelKeys());
            $usadas = [];

            foreach ($filas as $fila) {
                $checkin = null;
                if ($fila['referencia'] !== null && $porReferencia->has($fila['referencia'])) {
                    $candidata = (int) $porReferencia->get($fila['referencia'])->getKey();
                    $checkin = isset($usadas[$candidata]) ? null : $candidata;
                }
                if ($checkin === null) {
                    foreach ($porDia->get($fila['fecha'], []) as $id) {
                        if (! isset($usadas[(int) $id])) {
                            $checkin = (int) $id;
                            break;
                        }
                    }
                }
                if ($checkin !== null) {
                    $usadas[$checkin] = true;
                }
                PagoPlataformaTenant::query()->create([
                    'conciliacion_id' => $conciliacion->getKey(),
                    'fecha' => $fila['fecha'],
                    'referencia' => $fila['referencia'],
                    'monto_minor' => $fila['monto_minor'],
                    'checkin_id' => $checkin,
                ]);
            }

            return $conciliacion;
        });
    }

    /**
     * Lo esperado contra lo recibido de un mes, con lo que no cuadra.
     *
     * @return array<string, mixed>
     */
    public function resumen(string $proveedor, string $periodo): array
    {
        $visitas = $this->visitas($proveedor, $periodo)->load('sesion.oferta');
        $conciliacion = ConciliacionPlataformaTenant::query()->where('proveedor', $proveedor)->where('periodo', $periodo)->latest('id')->first();
        $pagos = $conciliacion?->pagos()->get() ?? collect();
        $pagadas = $pagos->pluck('checkin_id')->filter()->map(static fn (mixed $id): int => (int) $id)->flip();

        $esperado = 0;
        $sinTarifa = 0;
        $tarifas = [];
        foreach ($visitas as $v) {
            $dia = $this->fechas->fecha($v->registrado_en);
            $tarifas[$dia] ??= $this->tarifaEn($proveedor, $dia)?->monto_minor;
            if ($tarifas[$dia] === null) {
                $sinTarifa++;
            } else {
                $esperado += $tarifas[$dia];
            }
        }
        $recibido = (int) $pagos->sum('monto_minor');

        $presentarVisita = fn (CheckinTenant $c): array => [
            'id' => $c->ulid,
            'fecha' => $this->fechas->fecha($c->registrado_en),
            'clase' => $c->sesion?->oferta?->nombre,
            'referencia' => $c->referencia_externa,
        ];

        return [
            'proveedor' => $proveedor,
            'periodo' => $periodo,
            'moneda' => $this->parametros->moneda(),
            'visitas' => $visitas->count(),
            'visitas_sin_tarifa' => $sinTarifa,
            'esperado_minor' => $esperado,
            'recibido_minor' => $recibido,
            'diferencia_minor' => $recibido - $esperado,
            'importado' => $conciliacion === null ? null : [
                'archivo' => $conciliacion->archivo,
                'lineas' => $conciliacion->lineas,
                'fecha' => $conciliacion->created_at?->toIso8601String(),
            ],
            'visitas_sin_pago' => $conciliacion === null ? [] : $visitas
                ->filter(fn (CheckinTenant $c): bool => ! $pagadas->has((int) $c->getKey()))
                ->take(200)->map($presentarVisita)->values()->all(),
            'pagos_sin_visita' => $pagos->whereNull('checkin_id')->take(200)
                ->map(static fn (PagoPlataformaTenant $p): array => ['fecha' => $p->fecha->toDateString(), 'referencia' => $p->referencia, 'monto_minor' => $p->monto_minor])
                ->values()->all(),
            'montos_distintos' => $pagos->whereNotNull('checkin_id')
                ->filter(fn (PagoPlataformaTenant $p): bool => ($t = $tarifas[$p->fecha->toDateString()] ?? $this->tarifaEn($proveedor, $p->fecha->toDateString())?->monto_minor) !== null && $p->monto_minor !== $t)
                ->take(200)
                ->map(fn (PagoPlataformaTenant $p): array => [
                    'fecha' => $p->fecha->toDateString(),
                    'referencia' => $p->referencia,
                    'monto_minor' => $p->monto_minor,
                    'esperado_minor' => $tarifas[$p->fecha->toDateString()] ?? $this->tarifaEn($proveedor, $p->fecha->toDateString())?->monto_minor,
                ])->values()->all(),
        ];
    }

    /**
     * Las visitas validadas del mes (por el día del negocio). Las excepciones no:
     * la plataforma no las aprobó.
     *
     * @return Collection<int, CheckinTenant>
     */
    private function visitas(string $proveedor, string $periodo): Collection
    {
        $inicio = $this->fechas->inicioDelDia($periodo.'-01');
        $fin = $this->fechas->finDelDia(CarbonImmutable::parse($periodo.'-01')->endOfMonth()->toDateString());

        return CheckinTenant::query()
            ->where('proveedor', $proveedor)
            ->where('estado', CheckinTenant::VALIDADO)
            ->whereBetween('registrado_en', [$inicio, $fin])
            ->orderBy('registrado_en')
            ->orderBy('id')
            ->get();
    }

    /**
     * Las filas del CSV: fecha (AAAA-MM-DD o DD/MM/AAAA), monto y referencia.
     *
     * @return list<array{fecha: string, monto_minor: int, referencia: string|null}>
     */
    private function leer(string $contenido): array
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido;
        $lineas = preg_split('/\r\n|\r|\n/', trim($contenido)) ?: [];
        if (count($lineas) < 2) {
            throw new DomainException('El archivo no trae renglones de pagos.');
        }
        if (count($lineas) > self::MAX_LINEAS + 1) {
            throw new DomainException('El archivo trae más de '.self::MAX_LINEAS.' renglones: divídelo por mes.');
        }
        $separador = substr_count($lineas[0], ';') > substr_count($lineas[0], ',') ? ';' : ',';
        $cabecera = array_map(static fn (?string $c): string => mb_strtolower(trim((string) $c)), str_getcsv($lineas[0], $separador, '"', ''));
        // Por prioridad del nombre (no del orden de las columnas); «=id» pide el nombre exacto.
        $col = static function (array $nombres, array $excluir = []) use ($cabecera): ?int {
            foreach ($nombres as $n) {
                foreach ($cabecera as $i => $c) {
                    if (in_array($i, $excluir, true)) {
                        continue;
                    }
                    if (str_starts_with($n, '=') ? $c === substr($n, 1) : str_contains($c, $n)) {
                        return $i;
                    }
                }
            }

            return null;
        };
        $fecha = $col(['fecha', 'date', 'data', 'día', 'dia']);
        $monto = $col(['monto', 'importe', 'valor', 'value', 'amount', 'pago', 'total']);
        $ref = $fecha === null || $monto === null ? null : $col(['referencia', 'reference', 'check-in', 'checkin', 'token', '=id'], [$fecha, $monto]);
        if ($fecha === null || $monto === null) {
            throw new DomainException('No encontramos las columnas de fecha y monto en el encabezado del archivo.');
        }

        $filas = [];
        foreach (array_slice($lineas, 1) as $n => $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $valores = str_getcsv($linea, $separador, '"', '');
            $dia = self::fecha((string) ($valores[$fecha] ?? ''));
            $minor = self::monto((string) ($valores[$monto] ?? ''));
            if ($dia === null || $minor === null) {
                throw new DomainException('El renglón '.($n + 2).' no tiene una fecha o un monto que se entienda.');
            }
            $referencia = $ref !== null ? trim((string) ($valores[$ref] ?? '')) : '';
            $filas[] = ['fecha' => $dia, 'monto_minor' => $minor, 'referencia' => $referencia === '' ? null : mb_substr($referencia, 0, 120)];
        }

        return $filas;
    }

    private static function fecha(string $texto): ?string
    {
        $texto = trim($texto);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $texto, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $texto, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }

        return null;
    }

    /** «45.50», «$1,045.50» o «45,50» → centavos (sin flotantes). */
    private static function monto(string $texto): ?int
    {
        $limpio = preg_replace('/[^\d.,-]/', '', trim($texto)) ?? '';
        if ($limpio === '' || str_starts_with($limpio, '-')) {
            return null;
        }
        // Con punto y coma: el último es el decimal. Con solo coma y 1–2 decimales, la coma lo es.
        if (str_contains($limpio, '.') && str_contains($limpio, ',')) {
            $decimal = strrpos($limpio, '.') > strrpos($limpio, ',') ? '.' : ',';
        } elseif (preg_match('/,\d{1,2}$/', $limpio) === 1) {
            $decimal = ',';
        } else {
            $decimal = '.';
        }
        $miles = $decimal === '.' ? ',' : '.';
        $limpio = str_replace($miles, '', $limpio);
        [$enteros, $centavos] = array_pad(explode($decimal, $limpio, 2), 2, '0');
        if (! ctype_digit($enteros === '' ? '0' : $enteros) || ! ctype_digit($centavos)) {
            return null;
        }

        return (int) ($enteros === '' ? '0' : $enteros) * 100 + (int) str_pad(substr($centavos, 0, 2), 2, '0');
    }
}
