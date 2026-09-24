<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pagos;

/**
 * Estado de una devolución (refund) de un pago. Manual/efectivo se aprueba en el
 * momento (dinero devuelto en caja); una devolución en línea puede quedar
 * `pendiente` hasta que la pasarela la confirme (reconciliación), o `fallido`.
 */
enum EstadoReembolso: string
{
    case Pendiente = 'pendiente';
    case Aprobado = 'aprobado';
    case Fallido = 'fallido';
}
