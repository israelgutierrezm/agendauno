<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\GenerarCargoRenta;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Genera los cargos de renta del SaaS (plataforma→dueño) de cada estudio operativo para
 * un periodo (por defecto el mes actual). Idempotente por (estudio, periodo). Pensado
 * para el scheduler mensual; el dueño ve y paga el cargo en su apartado de renta.
 */
class GenerarCargosRenta extends Command
{
    protected $signature = 'turnouno:generar-cargos-renta {--periodo= : Periodo YYYY-MM (por defecto el mes anterior: cobro mes vencido)}';

    protected $description = 'Genera los cargos de renta del SaaS por estudio para un periodo';

    public function handle(GenerarCargoRenta $generar, GestorDeConexionTenant $gestor): int
    {
        $periodo = (string) ($this->option('periodo') ?: Carbon::now()->subMonthNoOverflow()->format('Y-m'));
        $generados = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$generados, $generar, $gestor, $periodo): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $generar->paraEstudio($estudio, $periodo);
                    $generados++;
                }
            });

        $this->info("Cargos de renta generados para {$periodo}: {$generados}.");

        return self::SUCCESS;
    }
}
