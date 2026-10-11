<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Presupuestos;

/**
 * Cómo presenta el negocio los impuestos en sus presupuestos: precios con impuestos
 * incluidos (el total es lo que paga; se informa cuánto contiene) o desglosados
 * (subtotal + impuesto = total). La tasa la fija el negocio.
 */
enum ModoImpuestos: string
{
    case Incluidos = 'incluidos';
    case Desglosados = 'desglosados';
}
