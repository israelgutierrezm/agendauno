<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * El horario en que se ofrecen las demos de la landing (ADR 0193, ajuste ADR 0204): lo
 * carga el superadmin en Demos › Horario. Son rangos por día de la semana (varios por
 * día, p. ej. de 10 a 14 y de 16 a 18) y días bloqueados (un feriado, vacaciones), todo
 * en la zona de ventas. Se guarda en la configuración de la plataforma; mientras no se
 * fije uno, rige el inicial: de lunes a viernes, de 10:00 a 18:00.
 */
final class HorarioDemos
{
    public const CLAVE = 'demos.horario';

    /** Topes de lo que se guarda (un horario semanal no necesita más). */
    public const MAXIMO_RANGOS = 40;

    public const MAXIMO_BLOQUEOS = 90;

    /** @var list<array{dia: int, desde: string, hasta: string}> */
    private const INICIAL = [
        ['dia' => 1, 'desde' => '10:00', 'hasta' => '18:00'],
        ['dia' => 2, 'desde' => '10:00', 'hasta' => '18:00'],
        ['dia' => 3, 'desde' => '10:00', 'hasta' => '18:00'],
        ['dia' => 4, 'desde' => '10:00', 'hasta' => '18:00'],
        ['dia' => 5, 'desde' => '10:00', 'hasta' => '18:00'],
    ];

    /**
     * Los rangos (día ISO: 1 = lunes … 7 = domingo) y los días bloqueados de hoy en
     * adelante (los pasados ya no cuentan).
     *
     * @return array{rangos: list<array{dia: int, desde: string, hasta: string}>, bloqueos: list<array{fecha: string, motivo: string|null}>}
     */
    public function leer(string $zona): array
    {
        $guardado = json_decode((string) ConfiguracionPlataforma::obtener(self::CLAVE), true);
        if (! is_array($guardado)) {
            return ['rangos' => self::INICIAL, 'bloqueos' => []];
        }
        $hoy = CarbonImmutable::now($zona)->toDateString();
        $rangos = [];
        foreach ((array) ($guardado['rangos'] ?? []) as $r) {
            if (is_array($r) && isset($r['dia'], $r['desde'], $r['hasta'])) {
                $rangos[] = ['dia' => (int) $r['dia'], 'desde' => (string) $r['desde'], 'hasta' => (string) $r['hasta']];
            }
        }
        $bloqueos = [];
        foreach ((array) ($guardado['bloqueos'] ?? []) as $b) {
            if (is_array($b) && isset($b['fecha']) && (string) $b['fecha'] >= $hoy) {
                $bloqueos[] = ['fecha' => (string) $b['fecha'], 'motivo' => isset($b['motivo']) ? (string) $b['motivo'] : null];
            }
        }

        return ['rangos' => $rangos, 'bloqueos' => $bloqueos];
    }

    /**
     * Revisa y guarda el horario: cada rango empieza antes de terminar, los de un mismo
     * día no se enciman y cada día bloqueado va una vez, de hoy en adelante. Se ordena
     * por día y hora.
     *
     * @param  list<array{dia: int, desde: string, hasta: string}>  $rangos
     * @param  list<array{fecha: string, motivo?: string|null}>  $bloqueos
     */
    public function guardar(array $rangos, array $bloqueos, string $zona): void
    {
        usort($rangos, fn (array $a, array $b): int => [$a['dia'], $a['desde']] <=> [$b['dia'], $b['desde']]);
        $anterior = null;
        foreach ($rangos as $i => $r) {
            if ($r['desde'] >= $r['hasta']) {
                throw ValidationException::withMessages(["rangos.{$i}.hasta" => ['Cada horario termina después de empezar.']]);
            }
            if ($anterior !== null && $anterior['dia'] === $r['dia'] && $r['desde'] < $anterior['hasta']) {
                throw ValidationException::withMessages(["rangos.{$i}.desde" => ['Dos horarios del mismo día se enciman: júntalos en uno.']]);
            }
            $anterior = $r;
        }

        $hoy = CarbonImmutable::now($zona)->toDateString();
        $fechas = [];
        $limpios = [];
        foreach ($bloqueos as $i => $b) {
            if ($b['fecha'] < $hoy) {
                throw ValidationException::withMessages(["bloqueos.{$i}.fecha" => ['Bloquea solo días de hoy en adelante.']]);
            }
            if (isset($fechas[$b['fecha']])) {
                throw ValidationException::withMessages(["bloqueos.{$i}.fecha" => ['Ese día ya está bloqueado.']]);
            }
            $fechas[$b['fecha']] = true;
            $motivo = trim((string) ($b['motivo'] ?? ''));
            $limpios[] = ['fecha' => $b['fecha'], 'motivo' => $motivo === '' ? null : $motivo];
        }
        usort($limpios, fn (array $a, array $b): int => $a['fecha'] <=> $b['fecha']);

        ConfiguracionPlataforma::establecer(self::CLAVE, (string) json_encode([
            'rangos' => $rangos,
            'bloqueos' => $limpios,
        ]));
    }

    /**
     * Los inicios de demo de un día (minutos desde la medianoche), con su duración: de
     * cada rango, uno cada `$minutos` mientras la demo termine dentro del rango. Un día
     * bloqueado no tiene ninguno.
     *
     * @param  array{rangos: list<array{dia: int, desde: string, hasta: string}>, bloqueos: list<array{fecha: string, motivo: string|null}>}  $horario
     * @return list<int>
     */
    public static function iniciosDelDia(array $horario, CarbonImmutable $dia, int $minutos): array
    {
        $fecha = $dia->toDateString();
        foreach ($horario['bloqueos'] as $b) {
            if ($b['fecha'] === $fecha) {
                return [];
            }
        }
        $inicios = [];
        foreach ($horario['rangos'] as $r) {
            if ($r['dia'] !== $dia->dayOfWeekIso) {
                continue;
            }
            $fin = self::minutosDe($r['hasta']);
            for ($m = self::minutosDe($r['desde']); $m + $minutos <= $fin; $m += $minutos) {
                $inicios[$m] = $m;
            }
        }
        ksort($inicios);

        return array_values($inicios);
    }

    private static function minutosDe(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora) + [0, 0]);

        return $h * 60 + $m;
    }
}
