<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Membresias;

/**
 * Cómo se reinicia el saldo de un derecho por ciclo (ver MEMBERSHIP_ENGINE.md).
 * `Ninguno` = pack sin reinicio (pierde el saldo al vencer, no se renueva).
 */
enum PoliticaReset: string
{
    case Ninguno = 'ninguno';
    case Calendario = 'calendario';
    case Aniversario = 'aniversario';
}
