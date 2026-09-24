<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Inventario\Exceptions;

/**
 * No hay stock suficiente del artículo en la sucursal para la venta o salida (R21).
 */
class StockInsuficiente extends InventarioException
{
    public function codigo(): string
    {
        return 'STOCK_INSUFFICIENT';
    }
}
