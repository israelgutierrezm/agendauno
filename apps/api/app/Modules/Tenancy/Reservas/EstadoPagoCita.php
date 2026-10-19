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
 * Null: no lleva cobro (la toma con su plan), su orden se canceló o la cita se
 * canceló (lo que pasó con su pago está en su historial). Una importada que se pagó
 * toda en el sistema anterior (R14) está pagada, aunque aquí no haya pago.
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
        if (in_array($reserva->estado, [EstadoReserva::Cancelada, EstadoReserva::Expirada], true)) {
            return null;
        }
        if ($reserva->estado === EstadoReserva::PendientePago) {
            return self::PorPagar;
        }
        if ($reserva->ordenPorCobrar() !== null) {
            return self::PorCobrar;
        }

        $orden = $reserva->orden;
        if ($orden?->estado === EstadoOrden::Pagada) {
            return self::Pagada;
        }

        return $orden !== null && $orden->total_minor === 0 && (int) $orden->pagado_fuera_minor > 0 ? self::Pagada : null;
    }
}
