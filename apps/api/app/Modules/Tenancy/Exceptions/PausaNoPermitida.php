<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * La membresía no se puede pausar o reanudar así: no está activa (o no está en
 * pausa), tiene un pago pendiente, o las fechas no son válidas.
 */
class PausaNoPermitida extends TenancyException
{
    public function codigo(): string
    {
        return 'MEMBERSHIP_PAUSE_NOT_ALLOWED';
    }
}
