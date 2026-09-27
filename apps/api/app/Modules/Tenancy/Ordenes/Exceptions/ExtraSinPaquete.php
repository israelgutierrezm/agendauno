<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Ordenes\Exceptions;

/**
 * Las clases extra se suman a un paquete vigente: sin uno, no hay a qué sumarlas.
 */
class ExtraSinPaquete extends OrdenException
{
    public function codigo(): string
    {
        return 'BASE_PACKAGE_REQUIRED';
    }
}
