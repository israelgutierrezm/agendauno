<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * El enlace para confirmar el registro no es válido, ya se usó o venció.
 */
class RegistroNoConfirmable extends TenancyException
{
    public function codigo(): string
    {
        return 'SIGNUP_CONFIRMATION_INVALID';
    }
}
