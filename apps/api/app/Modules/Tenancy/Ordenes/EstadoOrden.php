<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Ordenes;

/**
 * Estado de una orden de compra. `Pagada` dispara el fulfillment (concesión de
 * derechos). `Reembolsada` se maneja en Slice 9b.
 */
enum EstadoOrden: string
{
    case Pendiente = 'pendiente';
    case Pagada = 'pagada';
    case Cancelada = 'cancelada';
}
