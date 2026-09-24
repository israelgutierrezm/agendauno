<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas;

/**
 * Canal por el que entra una reserva (booking_source / revenue_source, R20): venta
 * directa del estudio o un agregador/marketplace. Permite reservar cupo por canal
 * (con liberación progresiva) y atribuir ingreso por fuente.
 */
enum CanalReserva: string
{
    case Directo = 'directo';
    case Wellhub = 'wellhub';
    case TotalPass = 'totalpass';
    case ClassPass = 'classpass';
    case Otro = 'otro';
}
