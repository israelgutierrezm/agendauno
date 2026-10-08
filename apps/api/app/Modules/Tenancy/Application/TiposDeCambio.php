<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Exceptions\TipoCambioNoDisponible;
use App\Modules\Tenancy\Models\TipoCambio;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tipo de cambio para cobrar en pesos la renta publicada en dólares (ADR 0107): el
 * FIX del Banco de México del día en que se emite el cargo (con `BANXICO_TOKEN`) o,
 * sin él, el último que capturó el superadmin. Se guarda por día (`tipos_cambio`) y
 * en diezmilésimas (17.2345 → 172345): nada de flotantes.
 *
 * Los días sin FIX (fin de semana, días inhábiles) rige el último publicado. Uno más
 * viejo que `renta.tipo_cambio_dias_vigencia` ya no se usa: el cargo espera y se
 * avisa al superadmin.
 *
 * @phpstan-type Tipo array{diezmilesimas: int, fecha: string, fuente: string}
 */
class TiposDeCambio
{
    public const FUENTE_BANXICO = 'banxico';

    public const FUENTE_MANUAL = 'manual';

    /** Días hacia atrás que se piden al Banco de México (cubren fines de semana y puentes). */
    private const DIAS_CONSULTA = 10;

    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly AlertasPlataforma $alertas,
    ) {}

    /**
     * Pesos por dólar vigentes en una fecha. Sin uno reciente, falla y (si se va a
     * cobrar, `$alertar`) avisa al superadmin.
     *
     * @return Tipo
     *
     * @throws TipoCambioNoDisponible
     */
    public function usdMxn(CarbonInterface $fecha, bool $alertar = true): array
    {
        $dia = $fecha->toDateString();
        $fila = $this->ultimoHasta($dia);
        if (($fila === null || $fila->fecha->toDateString() < $dia) && $this->banxicoConfigurado()) {
            $this->traerDeBanxico($dia);
            $fila = $this->ultimoHasta($dia);
        }

        $vigencia = max(1, $this->parametros->entero('renta.tipo_cambio_dias_vigencia'));
        if ($fila === null || $fila->fecha->lessThan(CarbonImmutable::parse($dia)->subDays($vigencia))) {
            if (! $alertar) {
                throw new TipoCambioNoDisponible('No hay un tipo de cambio reciente para convertir la renta a pesos.');
            }
            $this->alertas->registrar('renta', 'tipo-cambio', 'No hay tipo de cambio reciente para cobrar en pesos la renta en dólares: configura BANXICO_TOKEN o captura el del día.');

            throw new TipoCambioNoDisponible('No hay un tipo de cambio reciente para convertir la renta a pesos.');
        }

        return ['diezmilesimas' => $fila->diezmilesimas, 'fecha' => $fila->fecha->toDateString(), 'fuente' => $fila->fuente];
    }

    /**
     * El último tipo de cambio conocido (para mostrarlo), sin consultar a nadie.
     *
     * @return Tipo|null
     */
    public function ultimo(): ?array
    {
        $fila = $this->ultimoHasta(CarbonImmutable::now()->toDateString());

        return $fila === null ? null : ['diezmilesimas' => $fila->diezmilesimas, 'fecha' => $fila->fecha->toDateString(), 'fuente' => $fila->fuente];
    }

    /**
     * El superadmin captura el tipo de cambio de un día (sin token del Banco de México,
     * o para corregirlo).
     */
    public function registrarManual(int $diezmilesimas, CarbonInterface $fecha): void
    {
        TipoCambio::query()->updateOrCreate(
            ['fecha' => $fecha->toDateString(), 'de' => 'USD', 'a' => 'MXN'],
            ['diezmilesimas' => $diezmilesimas, 'fuente' => self::FUENTE_MANUAL],
        );
    }

    public function banxicoConfigurado(): bool
    {
        return (string) config('agendauno.banxico.token', '') !== '';
    }

    /**
     * Convierte un importe en minor con un tipo de cambio en diezmilésimas, redondeando
     * al centavo (medio hacia arriba).
     */
    public static function convertir(int $minor, int $diezmilesimas): int
    {
        return intdiv($minor * $diezmilesimas + 5000, 10000);
    }

    /** "17.2345" → 172345. Null si no es un tipo de cambio válido (mayor que cero). */
    public static function aDiezmilesimas(string $valor): ?int
    {
        if (preg_match('/^\s*(\d{1,4})(?:\.(\d{1,8}))?\s*$/', $valor, $m) !== 1) {
            return null;
        }
        $decimales = substr(str_pad($m[2] ?? '', 4, '0'), 0, 4);
        $diezmilesimas = (int) $m[1] * 10000 + (int) $decimales;

        return $diezmilesimas > 0 ? $diezmilesimas : null;
    }

    /** 172345 → "17.2345". */
    public static function formatear(int $diezmilesimas): string
    {
        return intdiv($diezmilesimas, 10000).'.'.str_pad((string) ($diezmilesimas % 10000), 4, '0', STR_PAD_LEFT);
    }

    private function ultimoHasta(string $dia): ?TipoCambio
    {
        return TipoCambio::query()
            ->where('de', 'USD')->where('a', 'MXN')
            ->whereDate('fecha', '<=', $dia)
            ->orderByDesc('fecha')
            ->first();
    }

    /**
     * Trae del Banco de México el FIX de los últimos días hasta `$dia` y lo guarda. Un
     * intento por día y hora: si Banxico no responde, se sigue con lo guardado.
     */
    private function traerDeBanxico(string $dia): void
    {
        if (! Cache::add('banxico:fix:'.$dia, true, 3600)) {
            return;
        }

        $fin = CarbonImmutable::parse($dia);
        $url = rtrim((string) config('agendauno.banxico.url'), '/').'/series/'.config('agendauno.banxico.serie')
            .'/datos/'.$fin->subDays(self::DIAS_CONSULTA)->toDateString().'/'.$dia;

        try {
            $respuesta = Http::timeout(10)->acceptJson()
                ->withHeaders(['Bmx-Token' => (string) config('agendauno.banxico.token')])
                ->get($url)->throw();
        } catch (Throwable $e) {
            $this->alertas->registrarExcepcion('renta', 'banxico', $e);

            return;
        }

        $datos = $respuesta->json('bmx.series.0.datos');
        foreach (is_array($datos) ? $datos : [] as $dato) {
            $fecha = is_array($dato) ? CarbonImmutable::createFromFormat('d/m/Y', (string) ($dato['fecha'] ?? '')) : null;
            $valor = self::aDiezmilesimas((string) ($dato['dato'] ?? ''));
            if (! $fecha instanceof CarbonImmutable || $valor === null) {
                // «N/E»: ese día no se publicó.
                continue;
            }
            // Lo que capturó el superadmin para un día se respeta.
            $existente = TipoCambio::query()->where('de', 'USD')->where('a', 'MXN')->whereDate('fecha', $fecha->toDateString())->first();
            if ($existente === null) {
                TipoCambio::query()->create([
                    'fecha' => $fecha->toDateString(), 'de' => 'USD', 'a' => 'MXN',
                    'diezmilesimas' => $valor, 'fuente' => self::FUENTE_BANXICO,
                ]);
            }
        }
    }
}
