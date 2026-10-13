<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

/**
 * Los lugares de una sesión como se publican en una plataforma (ADR 0149): los que tiene
 * (`total`), los que aún puede tomar esa plataforma (`libres`, con la regla única de cupo
 * y lo apartado para otros canales) y los que ya ocupan sus visitantes (`delCanal`).
 */
final readonly class LugaresDeHorario
{
    public function __construct(
        public int $total,
        public int $libres,
        public int $delCanal,
    ) {}
}
