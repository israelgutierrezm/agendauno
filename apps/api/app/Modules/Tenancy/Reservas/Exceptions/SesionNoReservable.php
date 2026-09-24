<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * La sesión no está en un estado que admita reservas (p. ej. cancelada).
 */
class SesionNoReservable extends ReservaException
{
    public function codigo(): string
    {
        return 'SESSION_NOT_BOOKABLE';
    }
}
