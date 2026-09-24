<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Ordenes\Exceptions;

/**
 * La orden no esta en un estado que admita liquidacion (p. ej. cancelada). Una
 * orden ya pagada no lanza esto: la liquidacion es idempotente.
 */
class OrdenNoLiquidable extends OrdenException
{
    public function codigo(): string
    {
        return 'ORDER_NOT_PAYABLE';
    }

    public function estadoHttp(): int
    {
        return 409;
    }
}
