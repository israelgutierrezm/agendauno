<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\LatidoCola;
use App\Modules\Platform\Operacion\LatidoOperacion;
use Illuminate\Console\Command;

/**
 * Latido de los procesos de fondo. Sin opciones (cada minuto, desde el programador):
 * marca el del programador y encola el de la cola. Con `--verificar=programador|cola`
 * responde si ese proceso latió hace poco (lo usa el chequeo de salud de Docker).
 */
class Latido extends Command
{
    protected $signature = 'agendauno:latido
        {--verificar= : programador o cola: sale con error si no ha latido hace poco}
        {--minutos=5 : Tolerancia en minutos al verificar}';

    protected $description = 'Marca (o verifica) el latido del programador de tareas y de la cola';

    public function handle(LatidoOperacion $latido): int
    {
        $proceso = $this->option('verificar');
        if (is_string($proceso) && $proceso !== '') {
            if (! in_array($proceso, [LatidoOperacion::PROGRAMADOR, LatidoOperacion::COLA], true)) {
                $this->error('Usa --verificar=programador o --verificar=cola.');

                return self::INVALID;
            }
            $estado = $latido->estado($proceso, max(1, (int) $this->option('minutos')));
            $ultimo = $latido->ultimo($proceso)?->toIso8601String() ?? 'nunca';
            $this->line("{$proceso}: {$estado} (último latido: {$ultimo})");

            return $estado === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $latido->marcar(LatidoOperacion::PROGRAMADOR);
        LatidoCola::dispatch();

        return self::SUCCESS;
    }
}
