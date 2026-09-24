<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Recursos;

/**
 * Cómo se cuenta la capacidad de un recurso.
 *
 * - Unidad: recurso identificado individualmente (un carril, un poste).
 * - Pool: cantidad intercambiable (N cupos equivalentes).
 */
enum ModoRecurso: string
{
    case Unidad = 'unidad';
    case Pool = 'pool';
}
