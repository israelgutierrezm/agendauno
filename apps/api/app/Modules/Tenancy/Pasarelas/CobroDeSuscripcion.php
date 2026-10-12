<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Un cobro que la pasarela hizo por su cuenta a una suscripción: aprobado o
 * rechazado (con el motivo para el cliente). `id` es único por intento, para no
 * registrarlo dos veces. Su moneda y su fecha, si la pasarela las da, sirven para
 * compararlo con la renovación y para saber si llegó después de cancelar (ADR 0134).
 */
final readonly class CobroDeSuscripcion
{
    public function __construct(
        public string $id,
        public bool $aprobado,
        public int $montoMinor,
        public ?string $motivo = null,
        public ?string $moneda = null,
        public ?CarbonImmutable $fecha = null,
    ) {}

    /**
     * La fecha que manda la pasarela (ISO 8601), o null si no la manda o no se lee.
     */
    public static function fechaDe(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($valor);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * El código de moneda (tres letras, en mayúsculas), o null.
     */
    public static function monedaDe(mixed $valor): ?string
    {
        return is_string($valor) && strlen(trim($valor)) === 3 ? strtoupper(trim($valor)) : null;
    }
}
