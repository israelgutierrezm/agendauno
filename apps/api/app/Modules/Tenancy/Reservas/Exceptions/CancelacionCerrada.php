<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * El cliente ya no puede cancelar él mismo: pasó el límite sin costo y el negocio
 * decidió que, después, solo él cancela (`cancelacion.cliente_cancela_tarde`).
 */
class CancelacionCerrada extends ReservaException
{
    public function codigo(): string
    {
        return 'CANCELLATION_CLOSED';
    }
}
