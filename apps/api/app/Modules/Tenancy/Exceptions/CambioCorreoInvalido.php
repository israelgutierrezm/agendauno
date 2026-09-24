<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * El enlace para confirmar el correo nuevo no es válido, ya se usó o venció, o ese
 * correo ya lo tomó otra cuenta mientras tanto.
 */
class CambioCorreoInvalido extends TenancyException
{
    public function codigo(): string
    {
        return 'EMAIL_CHANGE_INVALID';
    }
}
