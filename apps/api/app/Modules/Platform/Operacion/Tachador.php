<?php

declare(strict_types=1);

namespace App\Modules\Platform\Operacion;

use Illuminate\Support\Str;

/**
 * Tacha lo sensible de un texto antes de guardarlo en el monitoreo de errores (ADR
 * 0080): correos, llaves y tokens, y números largos (tarjetas, teléfonos, cuentas).
 * No es perfecto; por eso las trazas se guardan sin argumentos y las consultas SQL
 * sin sus valores.
 */
final class Tachador
{
    public static function texto(string $texto, int $maximo): string
    {
        $reglas = [
            // Correos.
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '[correo]',
            // Llaves de pasarelas (sk_live_…, whsec_…) y cabeceras de autorización.
            '/\b(?:sk|pk|rk|whsec)_[A-Za-z0-9_]+/' => '[llave]',
            '/\b(Bearer|Basic)\s+\S+/i' => '$1 [token]',
            // Tokens, hashes y cadenas aleatorias largas (un ULID, de 26, se queda).
            '/[A-Za-z0-9_\-]{32,}/' => '[token]',
            // Diez dígitos o más, con o sin espacios o guiones: tarjetas, teléfonos.
            '/(?<![\d])(?:\d[ \-]?){9,}\d(?![\d])/' => '[número]',
        ];
        $limpio = (string) preg_replace(array_keys($reglas), array_values($reglas), $texto);

        return Str::limit($limpio, $maximo, '…');
    }
}
