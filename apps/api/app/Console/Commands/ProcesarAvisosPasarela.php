<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\AvisosPasarelaTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AvisoPasarelaTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Retoma los avisos de Stripe, Mercado Pago y OpenPay que quedaron pendientes en cada
 * negocio (ADR 0173): los que no se procesaron al llegar y los que fallaron y toca
 * reintentar.
 */
class ProcesarAvisosPasarela extends Command
{
    protected $signature = 'agendauno:procesar-avisos-pasarela';

    protected $description = 'Procesa los avisos pendientes de las pasarelas de cobro de cada negocio';

    public function handle(AvisosPasarelaTenant $avisos, GestorDeConexionTenant $gestor): int
    {
        $terminados = 0;
        Estudio::query()
            ->conPagosEnCurso()
            ->chunkById(100, function (Collection $estudios) use (&$terminados, $avisos, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }
                    $terminados += $gestor->ejecutarAislado($estudio, function () use ($avisos): int {
                        // Un negocio sin avisos (o sin la migración aún) no hace nada.
                        if (! Schema::connection('tenant')->hasTable('avisos_pasarela')
                            || ! AvisoPasarelaTenant::query()->where('estado', AvisoPasarelaTenant::PENDIENTE)->exists()) {
                            return 0;
                        }

                        return $avisos->procesarPendientes();
                    }, 0);
                }
            });
        $this->info("Avisos de pasarelas procesados: {$terminados}.");

        return self::SUCCESS;
    }
}
