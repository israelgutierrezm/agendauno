<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\ProspectosSinAtenderTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Marca en cada negocio operativo los prospectos que nadie atendió a tiempo, con su
 * tarea y su aviso (ADR 0138). Una sola vez por asignación.
 */
class RevisarProspectosSinAtender extends Command
{
    protected $signature = 'agendauno:revisar-prospectos-sin-atender';

    protected $description = 'Marca y avisa los prospectos que nadie atendió a tiempo';

    public function handle(ProspectosSinAtenderTenant $sinAtender, GestorDeConexionTenant $gestor): int
    {
        $marcados = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$marcados, $sinAtender, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $marcados += $gestor->ejecutarAislado($estudio, fn (): int => $sinAtender->revisar(), 0);
                }
            });

        $this->info("Prospectos sin atender: {$marcados}.");

        return self::SUCCESS;
    }
}
