<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Membresias;

enum EstadoAcuerdo: string
{
    case Activo = 'activo';
    case Pausado = 'pausado';
    case Cancelado = 'cancelado';
    // Suspension involuntaria por falta de pago (dunning, R10). Al no estar `Activo`,
    // el acuerdo deja de resolver derechos para reservar/acceder y de renovar ciclos.
    case Suspendido = 'suspendido';
}
