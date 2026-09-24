<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * La persona no tiene un derecho (entitlement) vigente con saldo para la sesión.
 */
class SinDerechoDisponible extends ReservaException
{
    public function codigo(): string
    {
        return 'ENTITLEMENT_REQUIRED';
    }
}
