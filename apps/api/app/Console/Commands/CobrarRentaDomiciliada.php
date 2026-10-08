<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\DomiciliacionRenta;
use Illuminate\Console\Command;

/**
 * Cobra a la tarjeta domiciliada la renta pendiente: la recién emitida y los
 * reintentos que ya tocan (a los 3 y a los 7 días, ADR 0107).
 */
class CobrarRentaDomiciliada extends Command
{
    protected $signature = 'agendauno:cobrar-renta-domiciliada';

    protected $description = 'Cobra la renta pendiente a las tarjetas domiciliadas y reintenta los rechazos';

    public function handle(DomiciliacionRenta $domiciliacion): int
    {
        $this->info('Rentas cobradas a la tarjeta: '.$domiciliacion->cobrarPendientes().'.');

        return self::SUCCESS;
    }
}
