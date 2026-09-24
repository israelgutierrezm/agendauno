<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Creditos;

/**
 * Estados de una retención (hold) de créditos.
 */
enum EstadoRetencion: string
{
    case Activa = 'activa';
    case Consumida = 'consumida';
    case Liberada = 'liberada';
    case Perdida = 'perdida';
}
