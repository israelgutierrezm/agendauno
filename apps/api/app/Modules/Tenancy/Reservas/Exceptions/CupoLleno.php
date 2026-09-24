<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * La sesión alcanzó su capacidad: no hay cupo disponible.
 */
class CupoLleno extends ReservaException
{
    public function codigo(): string
    {
        return 'CAPACITY_FULL';
    }

    public function estadoHttp(): int
    {
        return 409;
    }
}
