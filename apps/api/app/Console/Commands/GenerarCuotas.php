<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\AnualidadesTenant;
use App\Modules\Tenancy\Application\BajaDeMembresiaTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lo diario de contratos y cuotas en cada negocio operativo (plan de mejoras B04):
 *
 * - genera la anualidad que toca hoy (aniversario de la alta o fecha fija), por cobrar;
 *   no se carga sola a la tarjeta y no se duplica si corre de nuevo;
 * - cierra las membresías cuya baja ya llegó.
 */
class GenerarCuotas extends Command
{
    protected $signature = 'agendauno:generar-cuotas';

    protected $description = 'Genera las anualidades que tocan hoy y cierra las membresías dadas de baja';

    public function handle(AnualidadesTenant $anualidades, BajaDeMembresiaTenant $bajas, GestorDeConexionTenant $gestor): int
    {
        $generadas = 0;
        $cerradas = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$generadas, &$cerradas, $anualidades, $bajas, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $generadas += $gestor->ejecutarAislado($estudio, fn (): int => $anualidades->generar(), 0);
                    $cerradas += $gestor->ejecutarAislado($estudio, fn (): int => $bajas->aplicarVencidas(), 0);
                }
            });

        $this->info("Anualidades generadas: {$generadas}. Membresías cerradas por baja: {$cerradas}.");

        return self::SUCCESS;
    }
}
