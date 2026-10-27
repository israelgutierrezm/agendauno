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
use Illuminate\Support\Facades\DB;

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
        // Solo a quien tiene algo por enviar (ADR 0224): lo que anotó la última pasada y
        // los mensajes nuevos desde entonces.
        $estado = DB::table('cola_mensajes')->get(['estudio_id', 'tipo', 'pendientes', 'revisado_en'])
            ->keyBy(static fn (object $f): string => $f->estudio_id.':'.$f->tipo)
            ->all();

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$negocios, $gestor, $estado): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }
                    $alguno = false;
                    foreach ([ColaDeMensajes::AVISOS, ColaDeMensajes::CAMPANAS] as $tipo) {
                        if (ColaDeMensajes::tocaPasada($estado, (int) $estudio->getKey(), $tipo)) {
                            EnviarMensajesDelNegocio::dispatch((int) $estudio->getKey(), $tipo);
                            $alguno = true;
                        }
                    }
                    $negocios += $alguno ? 1 : 0;
                }
            });

        $this->info("Envío encolado para {$negocios} negocios.");

        return self::SUCCESS;
    }
}
