<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * No se puede transferir la reserva (no está activa, ya se marcó asistencia, o el
 * destino ya tiene lugar en la clase).
 */
class TransferenciaInvalida extends ReservaException
{
    public function codigo(): string
    {
        return 'TRANSFER_INVALID';
    }
}
