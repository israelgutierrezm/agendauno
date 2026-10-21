<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * La clase pide un nivel que el cliente aún no tiene (ADR 0187).
 */
class NivelRequerido extends ReservaException
{
    public function codigo(): string
    {
        return 'LEVEL_REQUIRED';
    }
}
