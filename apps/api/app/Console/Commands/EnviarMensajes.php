<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\EnviarMensajesTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Relay de comunicaciones (R28): envia los mensajes encolados (y reintenta los
 * fallidos) de cada estudio operativo. Pensado para correr frecuentemente por el
 * scheduler.
 */
class EnviarMensajes extends Command
{
    protected $signature = 'agendauno:enviar-mensajes';

    protected $description = 'Envia los mensajes encolados (y reintenta los fallidos) de cada estudio';

    public function handle(EnviarMensajesTenant $relay, GestorDeConexionTenant $gestor): int
    {
        $enviados = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$enviados, $relay, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $enviados += $gestor->ejecutarEn($estudio, fn (): int => $relay->ejecutar());
                }
            });

        $this->info("Mensajes enviados: {$enviados}.");

        return self::SUCCESS;
    }
}
