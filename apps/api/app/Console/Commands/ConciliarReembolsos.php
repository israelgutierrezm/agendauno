<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\ConciliarReembolsosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Vuelve a consultar, con la misma llave, las devoluciones en línea que quedaron sin
 * respuesta de la pasarela en cada estudio operativo. Con `--desde` (y `--hasta`), revisa
 * las devoluciones hechas en la pasarela a cobros de esas fechas, más viejos que los que
 * revisa cada noche (ADR 0227).
 */
class ConciliarReembolsos extends Command
{
    protected $signature = 'agendauno:conciliar-reembolsos {--desde= : AAAA-MM-DD, revisa los cobros desde ese día} {--hasta= : AAAA-MM-DD}';

    protected $description = 'Aclara las devoluciones en línea que la pasarela no confirmó';

    public function handle(ConciliarReembolsosTenant $conciliar, GestorDeConexionTenant $gestor): int
    {
        $aclaradas = 0;
        $desde = is_string($this->option('desde')) && $this->option('desde') !== '' ? Carbon::parse((string) $this->option('desde'))->startOfDay() : null;
        $hasta = is_string($this->option('hasta')) && $this->option('hasta') !== '' ? Carbon::parse((string) $this->option('hasta'))->endOfDay() : null;

        Estudio::query()
            // También los suspendidos: un cobro o una devolución en la pasarela se
            // registra aunque el negocio esté suspendido.
            ->conPagosEnCurso()
            ->chunkById(100, function (Collection $estudios) use (&$aclaradas, $conciliar, $gestor, $desde, $hasta): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $aclaradas += $gestor->ejecutarAislado($estudio, fn (): int => $desde !== null
                        ? $conciliar->devolucionesEnLaPasarela($desde, $hasta)
                        : $conciliar->ejecutar(), 0);
                }
            });

        $this->info("Devoluciones aclaradas: {$aclaradas}.");

        return self::SUCCESS;
    }
}
