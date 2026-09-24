<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Ordenes\Exceptions;

/**
 * El código de promoción no aplica: inexistente, inactivo, vencido, sin usos
 * disponibles, o el subtotal no alcanza el mínimo (R22).
 */
class PromocionInvalida extends OrdenException
{
    public function codigo(): string
    {
        return 'PROMO_INVALID';
    }
}
