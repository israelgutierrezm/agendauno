<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pagos\Exceptions;

/**
 * Este cobro no se puede anular: el negocio no lo permite, no es un cobro en caja,
 * ya tuvo devoluciones, es de una renovación, ya se facturó, sus créditos ya se usaron
 * o pasó el plazo. El mensaje dice cuál (ADR 0087).
 */
class CobroNoAnulable extends PagoException
{
    public function codigo(): string
    {
        return 'PAYMENT_NOT_VOIDABLE';
    }
}
