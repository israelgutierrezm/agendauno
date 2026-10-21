<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * El plan llegó a su tope de reservas del día o de la semana (ADR 0182).
 */
class TopeDeReservas extends ReservaException
{
    public function codigo(): string
    {
        return 'BOOKING_LIMIT_REACHED';
    }
}
