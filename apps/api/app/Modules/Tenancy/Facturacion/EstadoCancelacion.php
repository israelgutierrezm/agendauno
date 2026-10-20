<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Facturacion;

/**
 * Cómo va la cancelación de un CFDI ante el SAT (ADR 0180). Si el cliente debe
 * aceptarla, queda en espera hasta que acepta, la rechaza o vence el plazo; aceptada,
 * la factura queda cancelada.
 */
enum EstadoCancelacion: string
{
    case Pendiente = 'pendiente';
    case Aceptada = 'aceptada';
    case Rechazada = 'rechazada';
    case Vencida = 'vencida';
}
