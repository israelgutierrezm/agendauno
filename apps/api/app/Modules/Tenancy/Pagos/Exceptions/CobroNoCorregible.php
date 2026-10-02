<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pagos\Exceptions;

/**
 * La forma de pago de este cobro no se puede corregir: el negocio no lo permite, no
 * es un cobro en caja, ya se devolvió, ya se facturó o pasó el plazo. El mensaje dice
 * cuál.
 */
class CobroNoCorregible extends PagoException
{
    public function codigo(): string
    {
        return 'PAYMENT_NOT_CORRECTABLE';
    }
}
