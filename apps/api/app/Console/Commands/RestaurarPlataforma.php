<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\RespaldosPlataforma;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reemplaza la base central de la plataforma con un respaldo (el más reciente, o el
 * indicado). Borra lo que haya: exige --force. Pon la aplicación en mantenimiento
 * antes (php artisan down). Los negocios se restauran aparte
 * (agendauno:restaurar-estudio).
 */
class RestaurarPlataforma extends Command
{
    protected $signature = 'agendauno:restaurar-plataforma
        {--respaldo= : Ruta del respaldo en el disco (por defecto, el más reciente)}
        {--listar : Solo lista los respaldos disponibles}
        {--force : Confirma que se reemplaza la base central}';

    protected $description = 'Restaura la base central de la plataforma desde un respaldo';

    public function handle(RespaldosPlataforma $respaldos): int
    {
        $disponibles = $respaldos->listar();
        if ($this->option('listar')) {
            foreach ($disponibles as $ruta) {
                $this->line($ruta);
            }
            if ($disponibles === []) {
                $this->warn('No hay respaldos de la plataforma.');
            }

            return self::SUCCESS;
        }

        $ruta = (string) ($this->option('respaldo') ?? ($disponibles[0] ?? ''));
        if ($ruta === '') {
            $this->error('No hay respaldos de la plataforma.');

            return self::FAILURE;
        }
        if (! $this->option('force')) {
            $this->error("Esto REEMPLAZA la base central con {$ruta}. Repite con --force para confirmar.");

            return self::FAILURE;
        }

        try {
            $respaldos->restaurarPlataforma($ruta);
        } catch (Throwable $e) {
            $this->error('No se pudo restaurar: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Plataforma restaurada desde {$ruta}.");

        return self::SUCCESS;
    }
}
