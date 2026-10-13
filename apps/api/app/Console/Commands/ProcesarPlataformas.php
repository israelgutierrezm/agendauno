<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\CheckinsAutomaticosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\EventoPlataformaTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Retoma los avisos de Wellhub y TotalPass que quedaron pendientes en cada negocio
 * (ADR 0148): los que no se procesaron al llegar o los que la plataforma no pudo
 * verificar y se reintentan mientras el check-in siga vigente.
 */
class ProcesarPlataformas extends Command
{
    protected $signature = 'agendauno:procesar-plataformas';

    protected $description = 'Procesa los avisos pendientes de Wellhub y TotalPass de cada negocio';

    public function handle(CheckinsAutomaticosTenant $checkins, GestorDeConexionTenant $gestor): int
    {
        $terminados = 0;
        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value, EstadoEstudio::Suspended->value])
            ->chunkById(100, function (Collection $estudios) use (&$terminados, $checkins, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }
                    $terminados += $gestor->ejecutarAislado($estudio, function () use ($checkins): int {
                        // Un negocio sin avisos (o sin la migración aún) no hace nada.
                        if (! Schema::connection('tenant')->hasTable('eventos_plataforma')
                            || ! EventoPlataformaTenant::query()->where('estado', EventoPlataformaTenant::PENDIENTE)->exists()) {
                            return 0;
                        }

                        return $checkins->procesarPendientes();
                    }, 0);
                }
            });
        $this->info("Avisos de plataformas procesados: {$terminados}.");

        return self::SUCCESS;
    }
}
