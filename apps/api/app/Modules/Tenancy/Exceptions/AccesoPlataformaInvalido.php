<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * La entrada a la plataforma no procede: los datos no coinciden o el código no sirve
 * (ADR 0126). El mensaje nunca dice cuál de los datos falló.
 */
class AccesoPlataformaInvalido extends TenancyException
{
    public function codigo(): string
    {
        return 'PLATFORM_ACCESS_DENIED';
    }
}
