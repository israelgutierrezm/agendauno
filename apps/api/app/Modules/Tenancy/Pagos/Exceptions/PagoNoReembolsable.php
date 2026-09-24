<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pagos\Exceptions;

/**
 * Solo se puede reembolsar un pago aprobado.
 */
class PagoNoReembolsable extends PagoException
{
    public function codigo(): string
    {
        return 'PAYMENT_NOT_REFUNDABLE';
    }
}
