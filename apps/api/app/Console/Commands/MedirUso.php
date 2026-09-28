<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\MedirUsoSaas;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Mide el uso de cada estudio operativo según su modalidad (alumnos activos o
 * profesionales activos) y guarda el agregado en el control plane. `--congelar`
 * cierra el periodo (fija la medición para facturar). Idempotente y por lotes.
 */
class MedirUso extends Command
{
    protected $signature = 'agendauno:medir-uso {--periodo=} {--congelar}';

    protected $description = 'Mide el uso de cada estudio (alumnos o profesionales activos) para el cobro del SaaS';

    public function handle(MedirUsoSaas $medir): int
    {
        $periodo = is_string($this->option('periodo')) && $this->option('periodo') !== ''
            ? (string) $this->option('periodo')
            : now()->format('Y-m');
        $congelar = (bool) $this->option('congelar');

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use ($medir, $periodo, $congelar): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    $medicion = $congelar
                        ? $medir->congelar($estudio, $periodo)
                        : $medir->ejecutar($estudio, $periodo);

                    $this->line("{$estudio->slug} [{$periodo}]: {$medicion->cantidad} {$medicion->metrica}");
                }
            });

        return self::SUCCESS;
    }
}
