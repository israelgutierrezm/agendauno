<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\GestionarDunningTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Escala el dunning (R10) de cada estudio operativo: suspende las membresías cuyo
 * periodo de gracia venció y siguen sin pago. Pensado para correr a diario.
 */
class EscalarDunning extends Command
{
    protected $signature = 'agendauno:escalar-dunning';

    protected $description = 'Suspende las membresias morosas cuyo periodo de gracia vencio';

    public function handle(GestionarDunningTenant $dunning, GestorDeConexionTenant $gestor): int
    {
        $suspendidas = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$suspendidas, $dunning, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $suspendidas += $gestor->ejecutarEn($estudio, fn (): int => $dunning->escalarVencidos());
                }
            });

        $this->info("Membresias suspendidas por morosidad: {$suspendidas}.");

        return self::SUCCESS;
    }
}
