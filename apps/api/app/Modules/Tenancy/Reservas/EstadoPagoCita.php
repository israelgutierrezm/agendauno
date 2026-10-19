<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas;

use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;

/**
 * El pago de una cita, aparte de su atención (2.6), calculado por el servidor
 * (ADR 0104):
 * - `por_pagar`: apartada en línea, falta que el cliente la pague;
 * - `por_cobrar`: su orden sigue pendiente (se cobra en caja o en línea), o pagó el
 *   anticipo y falta el saldo (ADR 0161);
 * - `pagada`.
 *
 * Null: no lleva cobro (la toma con su plan) o su orden se canceló.
 */
enum EstadoPagoCita: string
{
    case Pagada = 'pagada';
    case PorCobrar = 'por_cobrar';
    case PorPagar = 'por_pagar';

    /**
     * @param  ReservaTenant  $reserva  la del titular de la cita (con su orden y la del saldo)
     */
    public static function de(ReservaTenant $reserva): ?self
    {
        if ($reserva->estado === EstadoReserva::PendientePago) {
            return self::PorPagar;
        }
        if ($reserva->ordenPorCobrar() !== null) {
            return self::PorCobrar;
        }

        return $reserva->orden?->estado === EstadoOrden::Pagada ? self::Pagada : null;
    }
}
