<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * El pase QR no es de este negocio, fue alterado o ya caducó.
 */
class PaseAccesoInvalido extends TenancyException
{
    public function codigo(): string
    {
        return 'ACCESS_PASS_INVALID';
    }
}
