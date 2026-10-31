<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\ReservasTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Expira las ofertas de lista de espera vencidas de cada estudio operativo (R7):
 * libera su hold, las marca `expirada` y re-ofrece el cupo al siguiente. Después ofrece
 * a cada lista lo que esté libre, como los lugares de un canal que llegaron a su hora de
 * liberarse (ADR 0230). Pensado para correr con frecuencia por el scheduler.
 */
class ExpirarOfertas extends Command
{
    protected $signature = 'agendauno:expirar-ofertas';

    protected $description = 'Expira las ofertas de lista de espera vencidas y ofrece a cada lista los lugares libres';

    public function handle(ReservasTenant $reservas, GestorDeConexionTenant $gestor): int
    {
        $expiradas = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$expiradas, $reservas, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $expiradas += $gestor->ejecutarAislado($estudio, function () use ($reservas): int {
                        $vencidas = $reservas->expirarOfertasVencidas();
                        $reservas->promoverListasDeEspera();

                        return $vencidas;
                    }, 0);
                }
            });

        $this->info("Ofertas expiradas: {$expiradas}.");

        return self::SUCCESS;
    }
}
