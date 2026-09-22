<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Estado de un cargo de renta del SaaS (la suscripción que AgendaUno cobra al estudio):
 * pendiente de pago hasta que se liquida.
 */
enum EstadoCargoRenta: string
{
    case Pendiente = 'pendiente';
    case Pagado = 'pagado';
}
