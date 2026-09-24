<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * No se puede activar el pago automático: la membresía no se renueva, ya terminó o
 * la pasarela del negocio no admite cargos automáticos.
 */
class DomiciliacionNoPermitida extends TenancyException
{
    public function codigo(): string
    {
        return 'DIRECT_DEBIT_NOT_ALLOWED';
    }
}
