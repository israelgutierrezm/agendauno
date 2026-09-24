<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * El enlace para restablecer la contraseña no es válido, ya se usó o venció.
 */
class RestablecimientoInvalido extends TenancyException
{
    public function codigo(): string
    {
        return 'PASSWORD_RESET_INVALID';
    }
}
