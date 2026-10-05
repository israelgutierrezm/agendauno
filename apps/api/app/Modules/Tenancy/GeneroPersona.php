<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Género de una persona (opcional), en una lista breve e incluyente. Lo dice la
 * persona o lo anota el negocio con su consentimiento; nunca es obligatorio.
 */
enum GeneroPersona: string
{
    case Mujer = 'mujer';
    case Hombre = 'hombre';
    case NoBinario = 'no_binario';
    case Otro = 'otro';
    case PrefieroNoDecir = 'prefiero_no_decir';
}
