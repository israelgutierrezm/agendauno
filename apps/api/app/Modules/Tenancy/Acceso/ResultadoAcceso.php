<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Acceso;

/**
 * Resultado de un intento de acceso evaluado por la politica (R12).
 */
enum ResultadoAcceso: string
{
    case Permitido = 'permitido';
    case Denegado = 'denegado';
}
