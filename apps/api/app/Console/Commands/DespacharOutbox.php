<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\DespacharOutboxTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Relay del OUTBOX (R39): recorre cada estudio operativo y publica los eventos de
 * dominio pendientes de su BD (at-least-once). Pensado para correr frecuentemente por
 * el scheduler. Habilita comunicaciones, webhooks salientes, analitica y automatizacion.
 */
class DespacharOutbox extends Command
{
    protected $signature = 'agendauno:despachar-outbox';

    protected $description = 'Publica los eventos de dominio pendientes en el outbox de cada estudio';

    public function handle(DespacharOutboxTenant $relay, GestorDeConexionTenant $gestor): int
    {
        $publicados = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$publicados, $relay, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $publicados += $gestor->ejecutarAislado($estudio, fn (): int => $relay->ejecutar(), 0);
                }
            });

        $this->info("Eventos de dominio publicados: {$publicados}.");

        return self::SUCCESS;
    }
}
