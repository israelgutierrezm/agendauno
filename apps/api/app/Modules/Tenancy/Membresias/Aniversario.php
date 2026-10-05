<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Membresias;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * La única regla de aniversarios mensuales (ciclos de créditos y fecha de cobro): el
 * mismo día del mes que el ancla (el día en que empezó); si ese mes no lo tiene (29,
 * 30 o 31), su último día, y al mes siguiente se vuelve al día del ancla. Nunca se
 * desborda al mes que sigue: del 31 de enero, el siguiente aniversario es el 28 (o 29)
 * de febrero, no el 3 de marzo; y después, el 31 de marzo.
 *
 * Un ciclo va de su inicio a un día antes del siguiente aniversario: del 31 de enero
 * al 27 de febrero, luego del 28 de febrero al 30 de marzo.
 */
final class Aniversario
{
    /** El aniversario `$meses` después del mes de `$fecha`, anclado al día `$diaAncla`. */
    public static function siguiente(CarbonInterface|string $fecha, int $diaAncla, int $meses = 1): CarbonImmutable
    {
        $mes = CarbonImmutable::parse(self::dia($fecha))->startOfMonth()->addMonthsNoOverflow($meses);

        return $mes->setDay(min(max($diaAncla, 1), $mes->daysInMonth));
    }

    /**
     * Ventana [inicio, fin] (AAAA-MM-DD) del ciclo que empieza en `$inicio`.
     *
     * @return array{0: string, 1: string}
     */
    public static function ventana(CarbonInterface|string $inicio, int $diaAncla): array
    {
        return [self::dia($inicio), self::siguiente($inicio, $diaAncla)->subDay()->toDateString()];
    }

    /** El día del mes de una fecha (para guardarlo como ancla). */
    public static function diaDe(CarbonInterface|string $fecha): int
    {
        return (int) substr(self::dia($fecha), 8, 2);
    }

    private static function dia(CarbonInterface|string $fecha): string
    {
        return $fecha instanceof CarbonInterface ? $fecha->toDateString() : substr($fecha, 0, 10);
    }
}
