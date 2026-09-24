<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

/**
 * Desenlace de un intento de cobro en una pasarela. `Pendiente` = pago en línea
 * asíncrono cuyo resultado llegará por webhook.
 */
enum EstadoResultado
{
    case Aprobado;
    case Rechazado;
    case Pendiente;
}
