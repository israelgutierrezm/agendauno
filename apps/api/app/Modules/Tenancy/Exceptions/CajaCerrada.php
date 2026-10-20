<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * La sede usa caja y no tiene una abierta (ADR 0175): el dinero de mostrador no se
 * puede asentar. Se abre la caja (con su fondo) y se vuelve a intentar.
 */
class CajaCerrada extends TenancyException
{
    public function codigo(): string
    {
        return 'CASH_REGISTER_CLOSED';
    }
}
