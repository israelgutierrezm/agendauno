<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pagos\Exceptions;

/**
 * No se puede devolver eso de la venta del mostrador: está anulada, pasó el plazo, la
 * cantidad pasa de lo que queda por devolver o la forma elegida no alcanza. El mensaje
 * dice cuál (ADR 0178).
 */
class VentaNoDevolvible extends PagoException
{
    public function codigo(): string
    {
        return 'SALE_NOT_RETURNABLE';
    }
}
