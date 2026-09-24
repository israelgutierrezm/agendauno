<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

/**
 * Un cobro que la pasarela hizo por su cuenta a una suscripción: aprobado o
 * rechazado (con el motivo para el cliente). `id` es único por intento, para no
 * registrarlo dos veces.
 */
final readonly class CobroDeSuscripcion
{
    public function __construct(
        public string $id,
        public bool $aprobado,
        public int $montoMinor,
        public ?string $motivo = null,
    ) {}
}
