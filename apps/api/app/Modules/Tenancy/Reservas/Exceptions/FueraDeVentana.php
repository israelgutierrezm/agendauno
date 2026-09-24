<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * La sesión ya inició o quedó en el pasado: no se puede reservar.
 */
class FueraDeVentana extends ReservaException
{
    public function codigo(): string
    {
        return 'BOOKING_NOT_OPEN';
    }
}
