<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Platform\Operacion\RespaldosPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Simulacro de restauración (semanal): restaura el último respaldo de la plataforma,
 * el de un negocio y el de los archivos en lugares temporales, comprueba que con
 * ellos se podría volver a operar (tablas esenciales, datos, relaciones, una consulta
 * real y los archivos idénticos a los en uso) y los borra. No toca los datos en uso.
 * Si falla, avisa al superadmin; el resultado lo revisa agendauno:verificar-produccion.
 */
class SimulacroRestauracion extends Command
{
    protected $signature = 'agendauno:simulacro-restauracion {--estudio= : Slug del negocio a probar (por defecto, uno al azar)}';

    protected $description = 'Prueba que los respaldos se pueden restaurar (en bases temporales)';

    public function handle(RespaldosPlataforma $respaldos, AlertasPlataforma $alertas): int
    {
        $estudio = null;
        $slug = (string) ($this->option('estudio') ?? '');
        if ($slug !== '') {
            $estudio = Estudio::query()->where('slug', $slug)->first();
            if (! $estudio instanceof Estudio) {
                $this->error("No existe el negocio {$slug}.");

                return self::FAILURE;
            }
        }

        $resultado = $respaldos->simulacro($estudio);
        foreach ($resultado['pruebas'] as $p) {
            $this->line(($p['ok'] ? '<fg=green>OK   </>' : '<fg=red>FALLA</>')." {$p['respaldo']} — {$p['detalle']}");
            foreach ($p['comprobaciones'] as $c) {
                $this->line('      '.($c['ok'] ? '<fg=green>✓</>' : '<fg=red>✗</>')." {$c['nombre']}: {$c['detalle']}");
            }
        }

        if (! $resultado['ok']) {
            $fallas = collect($resultado['pruebas'])->reject(fn (array $p): bool => $p['ok'])
                ->map(fn (array $p): string => "{$p['respaldo']}: {$p['detalle']}")->implode('; ');
            $alertas->registrarExcepcion('simulacro_fallido', 'simulacro', new RuntimeException("El simulacro de restauración falló: {$fallas}"));
            $this->error('El simulacro falló.');

            return self::FAILURE;
        }
        $this->info('Los respaldos se restauran bien.');

        return self::SUCCESS;
    }
}
