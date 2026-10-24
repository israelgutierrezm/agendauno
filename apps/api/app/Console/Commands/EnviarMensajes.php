<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Comunicaciones\ColaDeMensajes;
use App\Modules\Tenancy\Comunicaciones\EnviarMensajesDelNegocio;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Relay de comunicaciones (R28): encola, por cada estudio operativo, una pasada de sus
 * avisos y otra de sus campañas (ADR 0205). El envío corre en la cola, no en el
 * programador: así un proveedor lento o una campaña grande no retrasan las demás tareas
 * programadas. Corre cada minuto; una pasada por negocio y tipo a la vez.
 */
class EnviarMensajes extends Command
{
    protected $signature = 'agendauno:enviar-mensajes';

    protected $description = 'Encola el envío de los mensajes (avisos y campañas) de cada estudio';

    public function handle(GestorDeConexionTenant $gestor): int
    {
        $negocios = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$negocios, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }
                    EnviarMensajesDelNegocio::dispatch((int) $estudio->getKey(), ColaDeMensajes::AVISOS);
                    EnviarMensajesDelNegocio::dispatch((int) $estudio->getKey(), ColaDeMensajes::CAMPANAS);
                    $negocios++;
                }
            });

        $this->info("Envío encolado para {$negocios} negocios.");

        return self::SUCCESS;
    }
}
