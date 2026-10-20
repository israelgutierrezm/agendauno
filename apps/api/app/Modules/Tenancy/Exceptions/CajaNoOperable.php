<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * Lo pedido no corresponde al estado de la caja (ADR 0175): mover dinero en una caja
 * ya contada, contar dos veces, cerrar sin contar, revisar lo que no lo necesita, o
 * cobrar sin saber en qué sede (con varias sedes que usan caja).
 */
class CajaNoOperable extends TenancyException
{
    public function codigo(): string
    {
        return 'CASH_REGISTER_INVALID_STATE';
    }
}
