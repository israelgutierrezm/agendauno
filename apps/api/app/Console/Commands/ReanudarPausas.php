<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\PausarMembresiaTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reanuda las membresías cuya pausa ya terminó (corre sus fechas por los días en
 * pausa). Antes de renovar ciclos y cobrar, para que ya cuenten como activas.
 */
class ReanudarPausas extends Command
{
    protected $signature = 'agendauno:reanudar-pausas';

    protected $description = 'Reanuda las membresías cuya pausa ya terminó';

    public function handle(PausarMembresiaTenant $pausas, GestorDeConexionTenant $gestor): int
    {
        $reanudadas = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$reanudadas, $pausas, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $reanudadas += $gestor->ejecutarAislado($estudio, fn (): int => $pausas->reanudarVencidas(), 0);
                }
            });

        $this->info("Membresías reanudadas: {$reanudadas}.");

        return self::SUCCESS;
    }
}
