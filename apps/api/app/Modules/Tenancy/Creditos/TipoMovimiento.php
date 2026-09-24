<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Creditos;

/**
 * Tipos de asiento del ledger de créditos.
 */
enum TipoMovimiento: string
{
    case Concesion = 'concesion';
    case Consumo = 'consumo';
    case Ajuste = 'ajuste';
    case AddOn = 'add_on';
    case Reverso = 'reverso';
    case Expiracion = 'expiracion';
}
