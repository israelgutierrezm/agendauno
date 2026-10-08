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
 * libera su hold, las marca `expirada` y re-ofrece el cupo al siguiente. Pensado para
 * correr con frecuencia por el scheduler.
 */
class ExpirarOfertas extends Command
{
    protected $signature = 'agendauno:expirar-ofertas';

    protected $description = 'Expira las ofertas de lista de espera vencidas y re-ofrece el cupo';

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

                    $expiradas += $gestor->ejecutarAislado($estudio, fn (): int => $reservas->expirarOfertasVencidas(), 0);
                }
            });

        $this->info("Ofertas expiradas: {$expiradas}.");

        return self::SUCCESS;
    }
}
