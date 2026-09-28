<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\ConciliarPagosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pregunta a la pasarela por los cobros en línea cuyo aviso no llegó, en cada estudio
 * operativo, y aplica lo que habría aplicado el aviso.
 */
class ConciliarPagos extends Command
{
    protected $signature = 'turnouno:conciliar-pagos {--estudio= : Slug de un solo estudio}';

    protected $description = 'Confirma con la pasarela los cobros en línea cuyo aviso no llegó';

    public function handle(ConciliarPagosTenant $conciliar, GestorDeConexionTenant $gestor): int
    {
        $total = ['aprobados' => 0, 'rechazados' => 0, 'en_espera' => 0];
        $slug = $this->option('estudio');

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->when(is_string($slug), fn ($q) => $q->where('slug', $slug))
            ->chunkById(100, function (Collection $estudios) use (&$total, $conciliar, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $cuenta = $gestor->ejecutarEn($estudio, fn (): array => $conciliar->ejecutar());
                    foreach ($cuenta as $clave => $n) {
                        $total[$clave] += $n;
                    }
                }
            });

        $this->info("Cobros confirmados: {$total['aprobados']}. Cerrados: {$total['rechazados']}. En espera: {$total['en_espera']}.");

        return self::SUCCESS;
    }
}
