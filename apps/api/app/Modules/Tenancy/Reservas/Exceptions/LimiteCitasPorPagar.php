<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * El cliente ya tiene tantas citas por pagar como el negocio permite
 * (`citas.maximo_por_pagar`, ADR 0065): paga o cancela alguna para agendar otra.
 */
class LimiteCitasPorPagar extends ReservaException
{
    public function codigo(): string
    {
        return 'UNPAID_APPOINTMENTS_LIMIT';
    }
}
