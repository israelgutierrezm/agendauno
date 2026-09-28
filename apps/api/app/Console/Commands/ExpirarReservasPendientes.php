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
 * Expira las reservas pendientes de pago (citas/pago-para-reservar) que no se pagaron
 * dentro de la ventana, en cada estudio operativo: libera el cupo, cancela la orden
 * pendiente y promueve la lista de espera. Pensado para correr con frecuencia.
 */
class ExpirarReservasPendientes extends Command
{
    protected $signature = 'agendauno:expirar-reservas-pago';

    protected $description = 'Libera reservas de pago-para-reservar no pagadas a tiempo';

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

                    $expiradas += $gestor->ejecutarEn($estudio, fn (): int => $reservas->expirarReservasPendientes());
                }
            });

        $this->info("Reservas de pago expiradas: {$expiradas}.");

        return self::SUCCESS;
    }
}
