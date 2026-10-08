<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\ConciliarCargosRenta;
use Illuminate\Console\Command;

/**
 * Pregunta a Stripe por la renta pendiente cuyo aviso no llegó y la confirma.
 */
class ConciliarRenta extends Command
{
    protected $signature = 'agendauno:conciliar-renta';

    protected $description = 'Confirma con Stripe los pagos de renta cuyo aviso no llegó';

    public function handle(ConciliarCargosRenta $conciliar): int
    {
        $this->info('Pagos de renta confirmados: '.$conciliar->ejecutar().'.');

        return self::SUCCESS;
    }
}
