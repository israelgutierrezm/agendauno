<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * El negocio solo recibe citas de clientes con cuenta (`citas.agendar_sin_cuenta`
 * apagado): desde su página se agenda entrando a la cuenta.
 */
class CuentaRequerida extends ReservaException
{
    public function codigo(): string
    {
        return 'ACCOUNT_REQUIRED';
    }

    public function estadoHttp(): int
    {
        return 403;
    }
}
