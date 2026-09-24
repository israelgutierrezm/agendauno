<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Lealtad;

/**
 * Estado de un canje de recompensa: se crea `pendiente` (puntos ya descontados) y el
 * staff lo marca `entregado` al dar la recompensa, o lo `cancela` (revierte puntos).
 */
enum EstadoCanje: string
{
    case Pendiente = 'pendiente';
    case Entregado = 'entregado';
    case Cancelado = 'cancelado';
}
