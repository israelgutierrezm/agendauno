<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

/**
 * Montos para las APIs que los piden en unidades mayores con decimales (Mercado
 * Pago, OpenPay). El sistema guarda centavos enteros; la conversión a decimal solo
 * ocurre aquí, al armar la petición, y de regreso se vuelve a centavos exactos.
 */
final class MontoDecimal
{
    /**
     * 129900 → 1299.0 (el número JSON que espera la pasarela).
     */
    public static function desdeCentavos(int $montoMinor): float
    {
        return round($montoMinor / 100, 2);
    }

    /**
     * 1299.5 / "1299.50" → 129950.
     */
    public static function aCentavos(float|int|string $monto): int
    {
        return (int) round(((float) $monto) * 100);
    }
}
