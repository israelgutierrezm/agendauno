<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * El negocio ya no tiene timbres para facturar (ADR 0107): compra un paquete en
 * «Mi suscripción».
 */
class TimbresAgotados extends TenancyException
{
    public function codigo(): string
    {
        return 'STAMPS_EXHAUSTED';
    }

    public function estadoHttp(): int
    {
        return 409;
    }
}
