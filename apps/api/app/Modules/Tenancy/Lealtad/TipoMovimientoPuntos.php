<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Lealtad;

/**
 * Tipo de movimiento en el ledger de puntos de lealtad (tenant-local). El saldo es
 * la SUMA de los deltas `puntos` (nunca se guarda). Acumulación y ajuste positivo
 * suman; canje y ajuste negativo restan.
 */
enum TipoMovimientoPuntos: string
{
    case Acumulacion = 'acumulacion'; // ganó puntos (asistencia/compra)
    case Canje = 'canje';             // gastó puntos en una recompensa
    case Ajuste = 'ajuste';           // ajuste manual del staff (+/-)
    case Reverso = 'reverso';         // reversa (p. ej. canje cancelado)
}
