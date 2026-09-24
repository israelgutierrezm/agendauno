<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pagos\Exceptions;

/**
 * No se puede reembolsar: algún derecho concedido por la orden ya tuvo uso
 * (consumo o una retención activa). Política MVP: solo se reembolsa lo intacto.
 */
class DerechoYaUsado extends PagoException
{
    public function codigo(): string
    {
        return 'REFUND_BLOCKED_USED';
    }
}
