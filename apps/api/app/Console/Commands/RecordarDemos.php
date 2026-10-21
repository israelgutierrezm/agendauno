<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\DemosAgendables;
use Illuminate\Console\Command;

/**
 * Recordatorios por correo de las demos agendadas desde la landing (ADR 0193): un día y
 * una hora antes, a quien la pidió.
 */
class RecordarDemos extends Command
{
    protected $signature = 'agendauno:recordar-demos';

    protected $description = 'Manda por correo los recordatorios de las demos agendadas (un día y una hora antes)';

    public function handle(DemosAgendables $demos): int
    {
        $this->info('Recordatorios de demos mandados: '.$demos->recordar().'.');

        return self::SUCCESS;
    }
}
