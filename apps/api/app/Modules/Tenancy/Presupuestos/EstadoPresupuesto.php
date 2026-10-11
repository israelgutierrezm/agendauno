<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Presupuestos;

/**
 * Estados de un presupuesto (ADR 0127). «Vencido» no se guarda: es un borrador o un
 * enviado cuya vigencia ya pasó.
 */
enum EstadoPresupuesto: string
{
    case Borrador = 'borrador';
    case Enviado = 'enviado';
    case Aceptado = 'aceptado';
    case Rechazado = 'rechazado';
    case Convertido = 'convertido';

    /** Se puede editar (aún no lo respondió el cliente). */
    public function editable(): bool
    {
        return $this === self::Borrador || $this === self::Enviado;
    }
}
