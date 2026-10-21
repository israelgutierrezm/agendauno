<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\CheckinTenant;
use App\Modules\Tenancy\Models\ConciliacionPlataformaTenant;
use App\Modules\Tenancy\Models\LugarPlataformaTenant;
use App\Modules\Tenancy\Models\PagoPlataformaTenant;
use App\Modules\Tenancy\Models\TarifaPlataformaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Conciliación de lo que pagan Wellhub, TotalPass y Fitpass (ADR 0185 y 0202). Ninguna
 * publica una API de pagos: lo esperado son las visitas validadas del mes por lo que paga
 * cada visita (la tarifa que capturó el negocio, con fecha de vigencia) y, si su
 * contrato lo dice, también las reservas que no se cancelaron a tiempo; lo recibido, el
 * reporte del portal de la plataforma que el negocio importa (CSV o Excel). Cada pago se
 * empareja con su visita por la referencia del check-in, por la persona (su Wellhub ID,
 * su código o su correo) y el día, o solo por el día.
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

    public function pactarTarifa(string $proveedor, int $montoMinor, string $desde, bool $pagaInasistencias = false): TarifaPlataformaTenant
    {
        return TarifaPlataformaTenant::query()->updateOrCreate(
            ['proveedor' => $proveedor, 'vigente_desde' => $desde],
            ['monto_minor' => $montoMinor, 'moneda' => $this->parametros->moneda(), 'paga_inasistencias' => $pagaInasistencias],
        );
    }

    /**
     * Las filas de un CSV (separado por comas o por punto y coma) como tabla de textos.
     *
     * @return list<list<string>>
     */
    public static function tablaDeCsv(string $contenido): array
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido;
        $lineas = preg_split('/\r\n|\r|\n/', trim($contenido)) ?: [];
        if ($lineas === []) {
            return [];
        }
        $separador = substr_count($lineas[0], ';') > substr_count($lineas[0], ',') ? ';' : ',';

        return array_values(array_map(
            static fn (string $linea): array => array_map(static fn (?string $c): string => (string) $c, str_getcsv($linea, $separador, '"', '')),
            array_filter($lineas, static fn (string $l): bool => trim($l) !== ''),
        ));
    }

    /**
     * Importa el reporte de pagos de un mes (reemplaza el anterior del mismo mes): la
     * tabla del CSV o del Excel con su encabezado. Columnas por su encabezado: fecha,
     * monto y, si vienen, la referencia del check-in y quién fue.
     *
     * @param  list<list<string>>  $tabla
     */
    public function importar(string $proveedor, string $periodo, array $tabla, ?string $archivo, ?Usuario $actor = null): ConciliacionPlataformaTenant
    {
        $filas = $this->leer($tabla);

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

            // Las visitas del mes (y las reservas que la plataforma paga aunque no
            // llegaran), para emparejarlas.
            $visitas = $this->visitas($proveedor, $periodo)->load('visitante');
            $porReferencia = $visitas->filter(fn (CheckinTenant $c): bool => (string) $c->referencia_externa !== '')
                ->keyBy(fn (CheckinTenant $c): string => (string) $c->referencia_externa);
            $visitasPorPersona = $this->porPersonaYDia($visitas->all(), fn (CheckinTenant $c): string => $this->fechas->fecha($c->registrado_en));
            $visitasPorDia = $visitas->groupBy(fn (CheckinTenant $c): string => $this->fechas->fecha($c->registrado_en))
                ->map(fn (Collection $c): array => $c->modelKeys())->all();
            $inasistencias = $this->inasistenciasCobrables($proveedor, $periodo);
            $faltasPorPersona = $this->porPersonaYDia($inasistencias->all(), fn (LugarPlataformaTenant $l): string => $this->fechas->fecha($l->sesion->inicia_en));
            $faltasPorDia = $inasistencias->groupBy(fn (LugarPlataformaTenant $l): string => $this->fechas->fecha($l->sesion->inicia_en))
                ->map(fn (Collection $c): array => $c->modelKeys())->all();
            $usadas = [];
            $usados = [];
            $libre = static function (array $ids, array &$tomadas): ?int {
                foreach ($ids as $id) {
                    if (! isset($tomadas[(int) $id])) {
                        $tomadas[(int) $id] = true;

                        return (int) $id;
                    }
                }

                return null;
            };

            foreach ($filas as $fila) {
                $persona = $fila['persona'] !== null ? $fila['persona'].'|'.$fila['fecha'] : null;
                $checkin = null;
                if ($fila['referencia'] !== null && $porReferencia->has($fila['referencia'])) {
                    $checkin = $libre([(int) $porReferencia->get($fila['referencia'])->getKey()], $usadas);
                }
                $checkin ??= $persona !== null ? $libre($visitasPorPersona[$persona] ?? [], $usadas) : null;
                $lugar = $checkin === null && $persona !== null ? $libre($faltasPorPersona[$persona] ?? [], $usados) : null;
                // Sin quién fue: la primera visita sin pago de ese día (o su reserva).
                if ($checkin === null && $lugar === null && $fila['persona'] === null) {
                    $checkin = $libre($visitasPorDia[$fila['fecha']] ?? [], $usadas);
                    $lugar = $checkin === null ? $libre($faltasPorDia[$fila['fecha']] ?? [], $usados) : null;
                }
                PagoPlataformaTenant::query()->create([
                    'conciliacion_id' => $conciliacion->getKey(),
                    'fecha' => $fila['fecha'],
                    'referencia' => $fila['referencia'] ?? $fila['persona'],
                    'monto_minor' => $fila['monto_minor'],
                    'checkin_id' => $checkin,
                    'lugar_id' => $lugar,
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
        $inasistencias = $this->inasistenciasCobrables($proveedor, $periodo);
        $conciliacion = ConciliacionPlataformaTenant::query()->where('proveedor', $proveedor)->where('periodo', $periodo)->latest('id')->first();
        $pagos = $conciliacion?->pagos()->get() ?? collect();
        $pagadas = $pagos->pluck('checkin_id')->filter()->map(static fn (mixed $id): int => (int) $id)->flip();
        $faltasPagadas = $pagos->pluck('lugar_id')->filter()->map(static fn (mixed $id): int => (int) $id)->flip();

        $esperado = 0;
        $sinTarifa = 0;
        $tarifas = [];
        $dias = [
            ...$visitas->map(fn (CheckinTenant $v): string => $this->fechas->fecha($v->registrado_en))->all(),
            ...$inasistencias->map(fn (LugarPlataformaTenant $l): string => $this->fechas->fecha($l->sesion->inicia_en))->all(),
        ];
        foreach ($dias as $dia) {
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
            // Reservas que no se cancelaron a tiempo y que la plataforma paga (su contrato).
            'inasistencias' => $inasistencias->count(),
            'inasistencias_sin_pago' => $conciliacion === null ? [] : $inasistencias
                ->filter(fn (LugarPlataformaTenant $l): bool => ! $faltasPagadas->has((int) $l->getKey()))
                ->take(200)
                ->map(fn (LugarPlataformaTenant $l): array => [
                    'id' => $l->ulid,
                    'fecha' => $this->fechas->fecha($l->sesion->inicia_en),
                    'clase' => $l->sesion->oferta?->nombre,
                    'estado' => $l->estado,
                ])->values()->all(),
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
            'pagos_sin_visita' => $pagos->whereNull('checkin_id')->whereNull('lugar_id')->take(200)
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
     * Las reservas del mes que no se cancelaron a tiempo (no llegó o canceló tarde) en
     * los días en que la tarifa dice que la plataforma también las paga.
     *
     * @return Collection<int, LugarPlataformaTenant>
     */
    private function inasistenciasCobrables(string $proveedor, string $periodo): Collection
    {
        $inicio = $this->fechas->inicioDelDia($periodo.'-01');
        $fin = $this->fechas->finDelDia(CarbonImmutable::parse($periodo.'-01')->endOfMonth()->toDateString());
        if (! TarifaPlataformaTenant::query()->where('proveedor', $proveedor)->where('paga_inasistencias', true)->exists()) {
            return new Collection;
        }

        return LugarPlataformaTenant::query()
            ->where('proveedor', $proveedor)
            ->where('origen', LugarPlataformaTenant::ORIGEN_RESERVA)
            ->whereIn('estado', [LugarPlataformaTenant::NO_ASISTIO, LugarPlataformaTenant::CANCELADO_TARDE])
            ->whereHas('sesion', fn ($q) => $q->whereBetween('inicia_en', [$inicio, $fin]))
            ->with(['sesion.oferta', 'visitante'])
            ->orderBy('id')
            ->get()
            ->filter(fn (LugarPlataformaTenant $l): bool => (bool) $this->tarifaEn($proveedor, $this->fechas->fecha($l->sesion->inicia_en))?->paga_inasistencias)
            ->values();
    }

    /**
     * Las visitas (o reservas) por «persona|día»: la persona por su identificador en la
     * plataforma (Wellhub ID, código de TotalPass) y por su correo.
     *
     * @template T of CheckinTenant|LugarPlataformaTenant
     *
     * @param  list<T>  $registros
     * @param  callable(T): string  $dia
     * @return array<string, list<int>>
     */
    private function porPersonaYDia(array $registros, callable $dia): array
    {
        $grupos = [];
        foreach ($registros as $registro) {
            $visitante = $registro->visitante;
            foreach (array_unique(array_filter([self::persona((string) $visitante?->id_externo), self::persona((string) $visitante?->email)])) as $clave) {
                $grupos[$clave.'|'.$dia($registro)][] = (int) $registro->getKey();
            }
        }

        return $grupos;
    }

    /** Quién fue, comparable: sin espacios y en minúsculas (un ID de solo números, sus dígitos). */
    private static function persona(string $texto): ?string
    {
        $texto = mb_strtolower(trim($texto));
        if ($texto === '') {
            return null;
        }
        $digitos = (string) preg_replace('/\D/', '', $texto);

        return preg_match('/^[\d\s\-.]+$/', $texto) === 1 && $digitos !== '' ? $digitos : (string) preg_replace('/\s+/', '', $texto);
    }

    /**
     * Las filas del reporte: fecha (AAAA-MM-DD, DD/MM/AAAA o la de Excel), monto,
     * referencia y quién fue.
     *
     * @param  list<list<string>>  $tabla
     * @return list<array{fecha: string, monto_minor: int, referencia: string|null, persona: string|null}>
     */
    private function leer(array $tabla): array
    {
        if (count($tabla) < 2) {
            throw new DomainException('El archivo no trae renglones de pagos.');
        }
        if (count($tabla) > self::MAX_LINEAS + 1) {
            throw new DomainException('El archivo trae más de '.self::MAX_LINEAS.' renglones: divídelo por mes.');
        }
        $cabecera = array_map(static fn (string $c): string => mb_strtolower(trim($c)), $tabla[0]);
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
        $ref = $fecha === null || $monto === null ? null : $col(['referencia', 'reference', 'check-in', 'checkin', 'token', 'folio', 'reservación', 'reservacion', 'booking', '=id'], [$fecha, $monto]);
        if ($fecha === null || $monto === null) {
            throw new DomainException('No encontramos las columnas de fecha y monto en el encabezado del archivo.');
        }
        // Quién fue: su Wellhub ID, su código o su correo.
        $quien = $col(['wellhub', 'gympass', 'id de usuario', 'user id', 'usuario', 'user', 'beneficiario', 'correo', 'email', 'e-mail'], array_filter([$fecha, $monto, $ref], static fn (?int $i): bool => $i !== null));

        $filas = [];
        foreach (array_slice($tabla, 1) as $n => $valores) {
            if (implode('', $valores) === '') {
                continue;
            }
            $dia = self::fecha((string) ($valores[$fecha] ?? ''));
            $minor = self::monto((string) ($valores[$monto] ?? ''));
            if ($dia === null || $minor === null) {
                throw new DomainException('El renglón '.($n + 2).' no tiene una fecha o un monto que se entienda.');
            }
            $referencia = $ref !== null ? trim((string) ($valores[$ref] ?? '')) : '';
            $persona = $quien !== null ? self::persona((string) ($valores[$quien] ?? '')) : null;
            $filas[] = [
                'fecha' => $dia,
                'monto_minor' => $minor,
                'referencia' => $referencia === '' ? null : mb_substr($referencia, 0, 120),
                'persona' => $persona === null ? null : mb_substr($persona, 0, 120),
            ];
        }

        return $filas;
    }

    private static function fecha(string $texto): ?string
    {
        $texto = trim($texto);
        // Una fecha de Excel: los días desde el 30 de diciembre de 1899.
        if (preg_match('/^\d{5}(\.\d+)?$/', $texto) === 1) {
            return CarbonImmutable::create(1899, 12, 30)?->addDays((int) $texto)->toDateString();
        }
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
