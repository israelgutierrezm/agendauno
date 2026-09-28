<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\VerificacionProduccion;
use Illuminate\Console\Command;

/**
 * ¿Está lista la instalación para operar? Lista cada punto (OK, AVISO o FALTA) con
 * qué hacer, y sale con error si falta algo crítico: sirve a mano al instalar o
 * actualizar el servidor, y en un paso de despliegue.
 *
 * Con --disponibilidad revisa solo lo que la versión necesita para atender
 * (actualizar.sh no quita el mantenimiento sin ello). Con --apertura (o
 * APERTURA_COMERCIAL=true) exige además Stripe en producción para la renta.
 */
class VerificarProduccion extends Command
{
    protected $signature = 'agendauno:verificar-produccion
        {--disponibilidad : Solo lo que la versión en marcha necesita para atender (actualizar.sh lo exige antes de abrir)}
        {--apertura : Exige además lo necesario para cobrar la renta con dinero real (como APERTURA_COMERCIAL=true)}';

    protected $description = 'Revisa si la instalación está lista para producción y dice qué falta';

    public function handle(VerificacionProduccion $verificacion): int
    {
        $disponibilidad = (bool) $this->option('disponibilidad');
        $apertura = (bool) $this->option('apertura') || $verificacion->aperturaComercial();
        $puntos = $disponibilidad ? $verificacion->disponibilidad() : $verificacion->revisar($apertura);
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
        $conAvisos = $avisos > 0 ? ", con {$avisos} aviso(s)" : '';
        if ($disponibilidad) {
            if ($faltan > 0) {
                $this->error("No está lista para atender: faltan {$faltan} punto(s).");

                return self::FAILURE;
            }
            $this->info('Lista para atender.');

            return self::SUCCESS;
        }
        if ($faltan > 0) {
            $this->error("Faltan {$faltan} punto(s) para operar en producción ({$avisos} aviso(s)).");

            return self::FAILURE;
        }
        $this->info($apertura
            ? "Lista para abrir y cobrar{$conAvisos}."
            : "Lista como instalación sin cobro real de la renta (APERTURA_COMERCIAL=false){$conAvisos}.");

        return self::SUCCESS;
    }
}
