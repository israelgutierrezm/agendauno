<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Membresias;

/**
 * Qué paga quien da de baja una membresía antes de cumplir su permanencia (plan de
 * mejoras B04; lo elige el negocio en cada plan).
 */
enum BajaAnticipada: string
{
    case SinPenalizacion = 'sin_penalizacion';
    case MontoFijo = 'monto_fijo';
    case MesesRestantes = 'meses_restantes';
}
