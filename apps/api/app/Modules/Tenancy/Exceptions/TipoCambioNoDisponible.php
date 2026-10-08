<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * No hay un tipo de cambio reciente para cobrar en pesos una renta publicada en
 * dólares (ADR 0107): ni del Banco de México ni capturado por el superadmin.
 */
class TipoCambioNoDisponible extends TenancyException
{
    public function codigo(): string
    {
        return 'EXCHANGE_RATE_UNAVAILABLE';
    }

    public function estadoHttp(): int
    {
        return 503;
    }
}
