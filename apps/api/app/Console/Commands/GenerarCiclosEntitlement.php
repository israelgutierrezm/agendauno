<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\GenerarCicloEntitlementTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Avanza los ciclos vencidos de los derechos recurrentes de todos los estudios.
 * Pensado para correr a diario por el scheduler.
 */
class GenerarCiclosEntitlement extends Command
{
    protected $signature = 'agendauno:generar-ciclos';

    protected $description = 'Reinicia/renueva los ciclos vencidos de los derechos recurrentes';

    public function handle(GenerarCicloEntitlementTenant $generarTenant, GestorDeConexionTenant $gestor): int
    {
        // Recorre cada estudio operativo y avanza los ciclos de sus derechos
        // recurrentes dentro de su propia base.
        $avanzados = 0;
        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$avanzados, $generarTenant, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $avanzados += $gestor->ejecutarEn($estudio, function () use ($generarTenant): int {
                        $n = 0;
                        DerechoTenant::query()
                            ->where('politica_reset', '!=', 'ninguno')
                            ->whereNotNull('ciclo_fin')
                            // Con su último día ya terminado en el negocio (no en UTC).
                            ->whereDate('ciclo_fin', '<', app(FechasNegocioTenant::class)->hoy())
                            ->chunkById(200, function (Collection $derechos) use (&$n, $generarTenant): void {
                                /** @var Collection<int, DerechoTenant> $derechos */
                                foreach ($derechos as $derecho) {
                                    $n += $generarTenant->ejecutar($derecho);
                                }
                            });

                        return $n;
                    });
                }
            });

        $this->info("Ciclos avanzados: {$avanzados}.");

        return self::SUCCESS;
    }
}
