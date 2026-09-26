<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\GenerarAgendaTenant;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Materializa la agenda recurrente (R5): por cada estudio operativo, genera las
 * sesiones de sus plantillas activas para los proximos N dias (idempotente). Pensado
 * para correr a diario por el scheduler (ventana deslizante).
 */
class GenerarAgenda extends Command
{
    protected $signature = 'turnouno:generar-agenda {--dias= : Días adelante (si no, lo que fija cada negocio)}';

    protected $description = 'Materializa las sesiones recurrentes de las plantillas de horario de cada estudio';

    public function handle(GenerarAgendaTenant $generar, GestorDeConexionTenant $gestor, ParametrosTenant $parametros): int
    {
        $forzado = $this->option('dias');
        $desde = now()->toDateString();
        $creadas = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$creadas, $generar, $gestor, $parametros, $forzado, $desde): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $creadas += $gestor->ejecutarEn($estudio, function () use ($generar, $parametros, $forzado, $desde): int {
                        // Cuántos días adelante: lo que fija el negocio (ADR 0045).
                        $dias = max(1, is_numeric($forzado) ? (int) $forzado : $parametros->entero('agenda.dias_a_generar'));
                        $hasta = now()->addDays($dias)->toDateString();
                        $n = 0;
                        PlantillaHorarioTenant::query()
                            ->where('activo', true)
                            ->chunkById(200, function (Collection $plantillas) use (&$n, $generar, $desde, $hasta): void {
                                /** @var Collection<int, PlantillaHorarioTenant> $plantillas */
                                foreach ($plantillas as $plantilla) {
                                    $n += $generar->ejecutar($plantilla, $desde, $hasta)->creadas;
                                }
                            });

                        return $n;
                    });
                }
            });

        $this->info("Sesiones generadas: {$creadas}.");

        return self::SUCCESS;
    }
}
