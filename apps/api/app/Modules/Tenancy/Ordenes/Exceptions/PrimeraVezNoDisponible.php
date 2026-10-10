<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Ordenes\Exceptions;

/**
 * Un producto «para tu primera vez» (clase de prueba) solo lo compra, una vez, quien
 * nunca vino al negocio (plan de mejoras B03; decisión del dueño 2026-10-10).
 */
class PrimeraVezNoDisponible extends OrdenException
{
    public function codigo(): string
    {
        return 'FIRST_VISIT_ONLY';
    }
}
