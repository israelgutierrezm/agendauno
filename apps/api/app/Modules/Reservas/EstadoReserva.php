<?php

declare(strict_types=1);

namespace App\Modules\Reservas;

/**
 * Estado de una reserva. `EnEspera` = lista de espera (waitlist): sin retención de
 * crédito. Al liberarse un cupo se OFRECE al siguiente (`Ofrecida`, con hold y ventana
 * de aceptación); si acepta pasa a `Confirmada`, si no acepta a tiempo, `Expirada`
 * (el cupo se re-ofrece al siguiente). Ver R7.
 */
enum EstadoReserva: string
{
    case Confirmada = 'confirmada';
    case EnEspera = 'en_espera';
    case Ofrecida = 'ofrecida';
    case Expirada = 'expirada';
    case Cancelada = 'cancelada';
    // Pago-para-reservar (citas): reservada pero pendiente de pago; RETIENE el cupo
    // hasta que se paga (→ confirmada) o expira (→ cancelada, libera el cupo).
    case PendientePago = 'pendiente_pago';
}
