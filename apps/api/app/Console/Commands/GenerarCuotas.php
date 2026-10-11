<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\AnualidadesTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Genera las cuotas que tocan hoy en cada negocio operativo (plan de mejoras B04): la
 * anualidad en el aniversario de la alta o en la fecha fija. Quedan por cobrar; no se
 * cargan solas a la tarjeta. Se puede correr de nuevo: no duplica.
 */
class GenerarCuotas extends Command
{
    protected $signature = 'agendauno:generar-cuotas';

    protected $description = 'Genera las anualidades que tocan hoy (cuotas por cobrar)';

    public function handle(AnualidadesTenant $anualidades, GestorDeConexionTenant $gestor): int
    {
        $generadas = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$generadas, $anualidades, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $generadas += $gestor->ejecutarAislado($estudio, fn (): int => $anualidades->generar(), 0);
                }
            });

        $this->info("Anualidades generadas: {$generadas}.");

        return self::SUCCESS;
    }
}
