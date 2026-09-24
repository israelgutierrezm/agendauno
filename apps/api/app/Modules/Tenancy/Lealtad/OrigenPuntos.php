<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Lealtad;

/**
 * Origen de un movimiento de puntos: para trazar de dónde salió cada acumulación o
 * canje (auditable).
 */
enum OrigenPuntos: string
{
    case Asistencia = 'asistencia'; // ganó por asistir a una clase
    case Compra = 'compra';         // ganó por una orden pagada
    case Manual = 'manual';         // ajuste del staff
    case Canje = 'canje';           // gasto por canjear una recompensa
}
