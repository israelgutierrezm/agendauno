<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Ordenes;

/**
 * Tipo de descuento de una promoción (R22). `Porcentaje` guarda su valor en puntos
 * base (1500 = 15%); `MontoFijo` en unidades menores de la moneda (nunca float).
 */
enum TipoPromocion: string
{
    case Porcentaje = 'porcentaje';
    case MontoFijo = 'monto_fijo';
}
