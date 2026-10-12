<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Medicion;

/**
 * El monto de una conversión como lo piden GA4 y Meta: un número decimal en la moneda
 * (89900 MXN → 899.00). Solo al mandarlo: en el sistema el dinero sigue en unidades
 * menores (`*_minor`) y nunca en flotante.
 */
final class ValorMonetario
{
    /** Monedas sin centavos (ISO 4217, exponente 0). */
    private const SIN_DECIMALES = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public static function decimal(int $minor, string $moneda): float
    {
        $exponente = in_array(strtoupper($moneda), self::SIN_DECIMALES, true) ? 0 : 2;

        return round($minor / (10 ** $exponente), $exponente);
    }
}
