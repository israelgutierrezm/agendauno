<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\RespaldosEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;

/**
 * Restaura la base de UN negocio desde uno de sus respaldos (por defecto el más
 * reciente). Reemplaza TODOS sus datos actuales: exige --force.
 */
class RestaurarEstudio extends Command
{
    protected $signature = 'turnouno:restaurar-estudio {estudio : slug del negocio} {--respaldo= : ruta del respaldo (por defecto el más reciente)} {--listar : solo muestra sus respaldos} {--force : confirma que se reemplazan sus datos}';

    protected $description = 'Restaura la base de datos de un negocio desde un respaldo';

    public function handle(RespaldosEstudio $respaldos): int
    {
        $estudio = Estudio::query()->where('slug', (string) $this->argument('estudio'))->first();
        if (! $estudio instanceof Estudio) {
            $this->error('No existe ese negocio.');

            return self::FAILURE;
        }

        $disponibles = $respaldos->listar($estudio);
        if ($this->option('listar')) {
            foreach ($disponibles as $ruta) {
                $this->line($ruta);
            }

            return self::SUCCESS;
        }

        $ruta = (string) ($this->option('respaldo') ?: ($disponibles[0] ?? ''));
        if ($ruta === '') {
            $this->error('Ese negocio no tiene respaldos.');

            return self::FAILURE;
        }
        if (! $this->option('force')) {
            $this->warn("Se reemplazarán todos los datos de {$estudio->slug} con {$ruta}. Repite con --force para continuar.");

            return self::FAILURE;
        }

        $respaldos->restaurar($estudio, $ruta);
        $this->info("{$estudio->slug} restaurado desde {$ruta}.");

        return self::SUCCESS;
    }
}
