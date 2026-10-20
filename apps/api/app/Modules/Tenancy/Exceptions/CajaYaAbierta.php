<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * La sede ya tiene una caja sin cerrar (ADR 0175): no se abre otra.
 */
class CajaYaAbierta extends TenancyException
{
    public function codigo(): string
    {
        return 'CASH_REGISTER_ALREADY_OPEN';
    }

    public function estadoHttp(): int
    {
        return 409;
    }
}
