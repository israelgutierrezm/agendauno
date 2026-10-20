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
 * Con --reintentar-aparcados, primero vuelve a la fila los que agotaron sus intentos
 * (ADR 0173), para cuando ya se corrigió lo que fallaba.
 */
class DespacharOutbox extends Command
{
    protected $signature = 'agendauno:despachar-outbox
        {--reintentar-aparcados : Vuelve a intentar los eventos que agotaron sus intentos}';

    protected $description = 'Publica los eventos de dominio pendientes en el outbox de cada estudio';

    public function handle(DespacharOutboxTenant $relay, GestorDeConexionTenant $gestor): int
    {
        $publicados = 0;
        $reintentados = 0;
        $aparcados = (bool) $this->option('reintentar-aparcados');

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$publicados, &$reintentados, $aparcados, $relay, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    if ($aparcados) {
                        $reintentados += $gestor->ejecutarAislado($estudio, fn (): int => $relay->reintentarAparcados(), 0);
                    }
                    $publicados += $gestor->ejecutarAislado($estudio, fn (): int => $relay->ejecutar(), 0);
                }
            });

        if ($aparcados) {
            $this->info("Eventos aparcados de vuelta en la fila: {$reintentados}.");
        }
        $this->info("Eventos de dominio publicados: {$publicados}.");

        return self::SUCCESS;
    }
}
