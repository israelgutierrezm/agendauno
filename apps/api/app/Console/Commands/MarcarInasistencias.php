<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\AsistenciaTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Al terminar una clase o cita, quien no tiene registro de asistencia «no se
 * presentó», con la política de inasistencias de su negocio (ADR 0101). Solo en los
 * negocios que lo tienen encendido (`asistencia.no_asistio_al_terminar`).
 */
class MarcarInasistencias extends Command
{
    protected $signature = 'agendauno:marcar-inasistencias';

    protected $description = 'Marca «no se presentó» a quien no tiene registro al terminar su clase o cita';

    public function handle(GestorDeConexionTenant $gestor): int
    {
        $marcadas = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$marcadas, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $marcadas += $gestor->ejecutarAislado($estudio, fn (): int => app(AsistenciaTenant::class)->marcarInasistenciasAlTerminar(), 0);
                }
            });

        $this->info("Inasistencias marcadas: {$marcadas}.");

        return self::SUCCESS;
    }
}
