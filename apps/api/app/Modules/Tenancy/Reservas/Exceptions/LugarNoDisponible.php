<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * El lugar elegido no está disponible: fuera de rango o ya tomado en la sesión (R4).
 */
class LugarNoDisponible extends ReservaException
{
    public function codigo(): string
    {
        return 'SPOT_UNAVAILABLE';
    }
}
