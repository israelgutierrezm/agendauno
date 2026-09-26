<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas;

/**
 * Quién canceló una reserva (fase 1, punto 1.4). Decide la política: solo la
 * cancelación del CLIENTE (él mismo, o recepción a petición suya) puede penalizar su
 * crédito; si cancela el NEGOCIO el crédito siempre regresa. `Sistema` = el propio
 * proceso (p. ej. una cita que venció sin pago).
 */
enum QuienCancela: string
{
    case Cliente = 'cliente';
    case Negocio = 'negocio';
    case Sistema = 'sistema';
}
