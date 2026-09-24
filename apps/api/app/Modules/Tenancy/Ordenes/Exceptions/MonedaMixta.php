<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Ordenes\Exceptions;

/**
 * Una orden no puede mezclar monedas: todas sus líneas deben compartir la misma
 * moneda (no se suman importes de monedas distintas).
 */
class MonedaMixta extends OrdenException
{
    public function codigo(): string
    {
        return 'MIXED_CURRENCY';
    }
}
