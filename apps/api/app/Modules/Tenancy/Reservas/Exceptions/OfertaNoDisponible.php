<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * La oferta de lista de espera no se puede aceptar: la reserva no esta `ofrecida` o
 * su ventana ya expiro (R7).
 */
class OfertaNoDisponible extends ReservaException
{
    public function codigo(): string
    {
        return 'OFFER_NOT_AVAILABLE';
    }

    public function estadoHttp(): int
    {
        return 409;
    }
}
