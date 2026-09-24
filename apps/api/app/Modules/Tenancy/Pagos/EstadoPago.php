<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pagos;

/**
 * Estado de un intento de pago. `ParcialmenteReembolsado` cubre las devoluciones
 * parciales (monetarias) que no cancelan la orden completa.
 */
enum EstadoPago: string
{
    case Pendiente = 'pendiente';
    case Aprobado = 'aprobado';
    case Rechazado = 'rechazado';
    case Reembolsado = 'reembolsado';
    case ParcialmenteReembolsado = 'parcialmente_reembolsado';
}
