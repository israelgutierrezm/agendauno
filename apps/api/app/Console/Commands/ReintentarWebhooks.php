<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Application\ReintentarWebhooksTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reintenta las entregas de webhook fallidas de cada estudio operativo (R40).
 * Pensado para correr frecuentemente por el scheduler.
 */
class ReintentarWebhooks extends Command
{
    protected $signature = 'agendauno:reintentar-webhooks';

    protected $description = 'Reintenta las entregas de webhook fallidas de cada estudio';

    public function handle(ReintentarWebhooksTenant $relay, GestorDeConexionTenant $gestor, FuncionesPlan $funciones): int
    {
        $reintentadas = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$reintentadas, $relay, $gestor, $funciones): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    // Sin integraciones en su plan (ADR 0107) ya no se le entregan webhooks.
                    $reintentadas += $gestor->ejecutarAislado(
                        $estudio,
                        fn (): int => $funciones->tiene($estudio, 'integraciones') ? $relay->ejecutar() : 0,
                        0,
                    );
                }
            });

        $this->info("Entregas de webhook reintentadas: {$reintentadas}.");

        return self::SUCCESS;
    }
}
