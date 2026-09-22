<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Estado de la suscripción del estudio con AgendaUno (facturación SaaS, control
 * plane). Independiente de los pagos que los alumnos hacen al estudio.
 */
enum EstadoFacturacion: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case GracePeriod = 'grace_period';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
}
