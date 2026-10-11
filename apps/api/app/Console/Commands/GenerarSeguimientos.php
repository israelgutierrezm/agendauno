<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\SeguimientoTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Crea las tareas de seguimiento del día en cada negocio operativo (plan de mejoras
 * M09/C06/C05): su primera clase, plan por vencer o vencido, días sin asistir y quien
 * compró y no ha reservado. No repite una tarea por el mismo motivo.
 */
class GenerarSeguimientos extends Command
{
    protected $signature = 'agendauno:generar-seguimientos';

    protected $description = 'Crea las tareas de seguimiento del día (sin repetirlas)';

    public function handle(SeguimientoTenant $seguimiento, GestorDeConexionTenant $gestor): int
    {
        $creadas = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$creadas, $seguimiento, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $creadas += $gestor->ejecutarAislado($estudio, fn (): int => $seguimiento->generar(), 0);
                }
            });

        $this->info("Seguimientos creados: {$creadas}.");

        return self::SUCCESS;
    }
}
