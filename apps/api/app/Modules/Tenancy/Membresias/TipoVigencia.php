<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Membresias;

/**
 * Cómo se cuenta la vigencia de un paquete o membresía desde la compra:
 * - `dias`: N días (del 14 de octubre, 30 días → 13 de noviembre).
 * - `meses`: N meses a la misma fecha (del 14 de octubre, 1 mes → 14 de noviembre).
 * - `fin_de_mes`: hasta el último día del mes de compra (1) o de los siguientes (2, 3…).
 *
 * El último día cuenta para reservar. Sin vigencia, no vence por fecha.
 */
enum TipoVigencia: string
{
    case Dias = 'dias';
    case Meses = 'meses';
    case FinDeMes = 'fin_de_mes';
}
