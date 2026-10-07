<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\LimpiezaDeAltasSinActivar;
use Illuminate\Console\Command;

/**
 * Borra los negocios cuyo dueño nunca activó su cuenta después de N días (parámetro
 * de plataforma `registro.dias_sin_activar`, 14 por omisión): su base, sus respaldos y
 * su registro. Nunca toca uno activado, con pagos o con más usuarios.
 */
class LimpiarAltasSinActivar extends Command
{
    protected $signature = 'agendauno:limpiar-altas-sin-activar
        {--dry-run : Solo dice qué negocios borraría}
        {--dias= : Días para activar en esta corrida (si no, el de la plataforma)}';

    protected $description = 'Borra los negocios cuyo dueño nunca activó su cuenta (base, respaldos y registro)';

    public function handle(LimpiezaDeAltasSinActivar $limpieza): int
    {
        $opcion = $this->option('dias');
        if ($opcion !== null && ! ctype_digit($opcion)) {
            $this->error('--dias debe ser un número entero de días.');

            return self::INVALID;
        }
        $dias = LimpiezaDeAltasSinActivar::acotar($opcion !== null ? (int) $opcion : $limpieza->dias());
        $simular = (bool) $this->option('dry-run');

        $resultado = $limpieza->ejecutar($dias, $simular);

        $verbo = $simular ? 'Se borrarían' : 'Borrados';
        $this->info("Negocios sin activar en {$dias} días. {$verbo}: ".count($resultado['borrados']).'. Se conservan: '.count($resultado['conservados']).'.');
        foreach ($resultado['borrados'] as $slug) {
            $this->line("  {$slug}");
        }
        // Por qué se conserva cada uno: al revisar (a diario serían todos los activados en prueba).
        if ($simular) {
            foreach ($resultado['conservados'] as $slug => $motivo) {
                $this->line("  Se conserva {$slug}: {$motivo}.");
            }
        }
        foreach ($resultado['errores'] as $slug => $error) {
            $this->error("  {$slug}: {$error}");
        }

        return $resultado['errores'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
