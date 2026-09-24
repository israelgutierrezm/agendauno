<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Creditos;

/**
 * Origen (source) de un asiento del ledger: DE DONDE nace el movimiento, con más
 * detalle que el {@see TipoMovimiento} (concesion/consumo/…). Permite auditar y
 * reportar por flujo de negocio (venta, top-up, ciclo, reserva, ajuste manual).
 */
enum OrigenMovimiento: string
{
    case Venta = 'venta';
    case TopUp = 'top_up';
    case Ciclo = 'ciclo';
    case Reserva = 'reserva';
    case Ajuste = 'ajuste';
    case Reembolso = 'reembolso';
}
