<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\PingFpm;
use Illuminate\Console\Command;

/**
 * El chequeo de salud de Docker del contenedor `api` (ADR 0173): sale con error si
 * PHP-FPM no contesta su ping por FastCGI (no acepta conexiones o sus procesos están
 * todos atorados).
 */
class SaludApi extends Command
{
    protected $signature = 'agendauno:salud-api
        {--host=127.0.0.1 : Dónde escucha PHP-FPM}
        {--puerto=9000 : Su puerto}
        {--segundos=5 : Cuánto esperar la respuesta}';

    protected $description = 'Sale con error si PHP-FPM no contesta su ping (chequeo de salud de Docker)';

    public function handle(PingFpm $ping): int
    {
        $fallo = $ping->fallo(
            (string) $this->option('host'),
            (int) $this->option('puerto'),
            max(1.0, (float) $this->option('segundos')),
        );
        if ($fallo !== null) {
            $this->error($fallo);

            return self::FAILURE;
        }
        $this->info('PHP-FPM contesta.');

        return self::SUCCESS;
    }
}
