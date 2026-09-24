<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * Solo se puede registrar asistencia sobre una reserva confirmada.
 */
class ReservaNoConfirmada extends ReservaException
{
    public function codigo(): string
    {
        return 'RESERVATION_NOT_CONFIRMED';
    }
}
