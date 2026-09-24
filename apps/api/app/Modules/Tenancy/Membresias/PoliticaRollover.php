<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Membresias;

/**
 * Qué pasa con el saldo no usado al cerrar un ciclo. `Limitado` acarrea hasta
 * `rollover_max` unidades.
 */
enum PoliticaRollover: string
{
    case Ninguno = 'ninguno';
    case Completo = 'completo';
    case Limitado = 'limitado';
}
