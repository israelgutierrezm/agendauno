<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Inventario;

/**
 * Tipo de movimiento del ledger de inventario (R21). El stock por (artículo, sucursal)
 * es la SUMA de los deltas de sus movimientos (derivado, nunca almacenado), igual que el
 * ledger de créditos.
 */
enum TipoMovimientoInventario: string
{
    case Entrada = 'entrada';   // reabastecimiento (delta +)
    case Salida = 'salida';     // venta / merma (delta -)
    case Ajuste = 'ajuste';     // conteo/correccion (delta + o -)
}
