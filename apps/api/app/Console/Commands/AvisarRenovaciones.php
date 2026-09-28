<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\AvisarRenovacionesTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Avisa a los alumnos de cada estudio operativo que su membresía se renueva en los
 * próximos días (cuándo, cuánto y cómo se paga). Pensado para correr a diario.
 */
class AvisarRenovaciones extends Command
{
    protected $signature = 'agendauno:avisar-renovaciones';

    protected $description = 'Avisa a los alumnos las renovaciones de membresía de los próximos días';

    public function handle(AvisarRenovacionesTenant $avisos, GestorDeConexionTenant $gestor): int
    {
        $avisados = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$avisados, $avisos, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $avisados += $gestor->ejecutarEn($estudio, fn (): int => $avisos->ejecutar());
                }
            });

        $this->info("Renovaciones avisadas: {$avisados}.");

        return self::SUCCESS;
    }
}
