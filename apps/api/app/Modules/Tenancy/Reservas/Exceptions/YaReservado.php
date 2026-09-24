<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * La persona ya tiene una reserva confirmada para esta sesión.
 */
class YaReservado extends ReservaException
{
    public function codigo(): string
    {
        return 'ALREADY_BOOKED';
    }

    public function estadoHttp(): int
    {
        return 409;
    }
}
