<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Ordenes\Exceptions;

/**
 * Un plan con permanencia (B04) se compra aceptando sus condiciones: el cliente en su
 * cuenta, o el negocio registra que el cliente las aceptó.
 */
class CondicionesNoAceptadas extends OrdenException
{
    public function codigo(): string
    {
        return 'TERMS_NOT_ACCEPTED';
    }
}
