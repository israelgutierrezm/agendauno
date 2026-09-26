<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * La reserva (o la clase) ya tiene asistencia registrada: cancelarla dejaría un
 * estado contradictorio (asistió pero está cancelada) y un crédito sin explicar.
 */
class ReservaYaAtendida extends ReservaException
{
    public function codigo(): string
    {
        return 'RESERVATION_ATTENDED';
    }

    public function estadoHttp(): int
    {
        return 409;
    }
}
