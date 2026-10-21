<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\ReservaConGoogle;
use Illuminate\Console\Command;

/**
 * Genera los feeds de Reserva con Google (ADR 0198) con las sedes de los negocios que
 * aparecen. La plataforma los sube al SFTP que da Google.
 */
class GenerarFeedsReservaGoogle extends Command
{
    protected $signature = 'agendauno:reserva-con-google';

    protected $description = 'Genera los feeds de Reserva con Google';

    public function handle(ReservaConGoogle $google): int
    {
        if (! $google->configurada()) {
            $this->info('Reserva con Google no está configurada.');

            return self::SUCCESS;
        }
        $this->info('Sedes en los feeds: '.$google->generarFeeds().'.');

        return self::SUCCESS;
    }
}
