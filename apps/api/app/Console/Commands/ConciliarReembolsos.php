<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\ConciliarReembolsosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Vuelve a consultar, con la misma llave, las devoluciones en línea que quedaron sin
 * respuesta de la pasarela en cada estudio operativo.
 */
class ConciliarReembolsos extends Command
{
    protected $signature = 'agendauno:conciliar-reembolsos';

    protected $description = 'Aclara las devoluciones en línea que la pasarela no confirmó';

    public function handle(ConciliarReembolsosTenant $conciliar, GestorDeConexionTenant $gestor): int
    {
        $aclaradas = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$aclaradas, $conciliar, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $aclaradas += $gestor->ejecutarEn($estudio, fn (): int => $conciliar->ejecutar());
                }
            });

        $this->info("Devoluciones aclaradas: {$aclaradas}.");

        return self::SUCCESS;
    }
}
