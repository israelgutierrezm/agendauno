<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\VerificacionProduccion;
use Illuminate\Console\Command;

/**
 * ¿Está lista la instalación para operar? Lista cada punto (OK, AVISO o FALTA) con
 * qué hacer, y sale con error si falta algo crítico: sirve a mano al instalar o
 * actualizar el servidor, y en un paso de despliegue.
 */
class VerificarProduccion extends Command
{
    protected $signature = 'turnouno:verificar-produccion';

    protected $description = 'Revisa si la instalación está lista para producción y dice qué falta';

    public function handle(VerificacionProduccion $verificacion): int
    {
        $puntos = $verificacion->revisar();
        $seccion = null;
        foreach ($puntos as $p) {
            if ($p['seccion'] !== $seccion) {
                $seccion = $p['seccion'];
                $this->newLine();
                $this->line("<options=bold>{$seccion}</>");
            }
            $etiqueta = match ($p['estado']) {
                'ok' => '<fg=green>OK   </>',
                'aviso' => '<fg=yellow>AVISO</>',
                default => '<fg=red>FALTA</>',
            };
            $this->line("  {$etiqueta} {$p['punto']}".($p['detalle'] !== '' ? " — {$p['detalle']}" : ''));
        }

        $faltan = count(array_filter($puntos, static fn (array $p): bool => $p['estado'] === 'falta'));
        $avisos = count(array_filter($puntos, static fn (array $p): bool => $p['estado'] === 'aviso'));
        $this->newLine();
        if ($faltan > 0) {
            $this->error("Faltan {$faltan} punto(s) para operar en producción ({$avisos} aviso(s)).");

            return self::FAILURE;
        }
        $this->info($avisos > 0 ? "Lista para producción, con {$avisos} aviso(s)." : 'Lista para producción.');

        return self::SUCCESS;
    }
}
