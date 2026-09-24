<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * La pasarela no respondió (red, caída o límite de peticiones): no se sabe si el
 * cobro pasó. No es culpa del cliente; se reintenta después con la misma llave de
 * idempotencia, para no cobrar dos veces.
 */
class CobroNoConcluyente extends TenancyException
{
    public function codigo(): string
    {
        return 'GATEWAY_TIMEOUT';
    }

    public function estadoHttp(): int
    {
        return 503;
    }
}
