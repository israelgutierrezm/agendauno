<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\GenerarRecordatoriosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Emite los recordatorios de clases y citas (24 h y 2 h antes) de cada estudio
 * operativo. Los mensajes salen después por el relay del outbox y el de mensajes.
 */
class EnviarRecordatorios extends Command
{
    protected $signature = 'agendauno:enviar-recordatorios';

    protected $description = 'Emite los recordatorios de clases y citas próximas (24 h y 2 h antes)';

    public function handle(GenerarRecordatoriosTenant $recordatorios, GestorDeConexionTenant $gestor): int
    {
        $emitidos = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$emitidos, $recordatorios, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $emitidos += $gestor->ejecutarAislado($estudio, fn (): int => $recordatorios->ejecutar(), 0);
                }
            });

        $this->info("Recordatorios emitidos: {$emitidos}.");

        return self::SUCCESS;
    }
}
