<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\SuspensionPorRenta;
use Illuminate\Console\Command;

/**
 * Suspende a los negocios con una renta vencida hace más días de gracia y reactiva
 * a los que ya pagaron (ADR 0073). Idempotente.
 */
class SuspenderPorRenta extends Command
{
    protected $signature = 'agendauno:suspender-por-renta';

    protected $description = 'Suspende por renta vencida (tras los días de gracia) y reactiva a quien ya pagó';

    public function handle(SuspensionPorRenta $suspension): int
    {
        ['suspendidos' => $suspendidos, 'reactivados' => $reactivados] = $suspension->revisar();

        $this->info("Suspendidos por renta: {$suspendidos}. Reactivados: {$reactivados}.");

        return self::SUCCESS;
    }
}
