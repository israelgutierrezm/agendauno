<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * La membresía no se puede dar de baja así (B04): no es una membresía que se renueva,
 * ya está cancelada o ya tiene una baja programada.
 */
class BajaDeMembresiaNoPermitida extends TenancyException
{
    public function codigo(): string
    {
        return 'MEMBERSHIP_CANCEL_NOT_ALLOWED';
    }
}
