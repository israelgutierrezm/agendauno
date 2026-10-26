<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Nomina;

/**
 * Como se calcula el pago de un miembro del staff (R17): un monto fijo por clase
 * impartida, por asistente presente, o por hora de clase; o, en un negocio de citas,
 * un porcentaje de lo que se cobra por sus citas (ADR 0219).
 */
enum TipoPago: string
{
    case PorClase = 'por_clase';
    case PorAsistente = 'por_asistente';
    case PorHora = 'por_hora';
    case PorcentajeVenta = 'porcentaje_venta';
}
