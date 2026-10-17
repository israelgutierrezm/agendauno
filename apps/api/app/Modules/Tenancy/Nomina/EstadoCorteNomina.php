<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Nomina;

/**
 * Un corte de nómina guardado: cerrado (la foto de lo devengado; aún se puede reabrir)
 * o pagado (con su referencia; lo que cambie después va en ajustes). El periodo
 * abierto no se guarda: se calcula al vuelo.
 */
enum EstadoCorteNomina: string
{
    case Cerrado = 'cerrado';
    case Pagado = 'pagado';
}
