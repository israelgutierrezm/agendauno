<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * El plan pedido no se puede contratar (ADR 0107): nivel o profesionales fuera de lo
 * que ofrece la tarifa, o menos profesionales de los que ya tiene el negocio.
 */
class PlanNoPermitido extends TenancyException
{
    public function codigo(): string
    {
        return 'PLAN_NOT_ALLOWED';
    }
}
