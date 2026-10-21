<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\FacturarCitasEnAutomatico;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reintenta las facturas automáticas de citas que no se timbraron y aplica con su CFDI
 * de egreso los anticipos que quedaron sin aplicar.
 */
class FacturarCitas extends Command
{
    protected $signature = 'agendauno:facturar-citas';

    protected $description = 'Reintenta las facturas automáticas de citas y aplica los anticipos pendientes';

    public function handle(FacturarCitasEnAutomatico $facturar, GestorDeConexionTenant $gestor): int
    {
        $hechas = 0;
        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$hechas, $facturar, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if ($estudio->modalidad()->value !== 'citas' || ! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }
                    $hechas += $gestor->ejecutarAislado($estudio, fn (): int => $facturar->pendientes(), 0);
                }
            });
        $this->info("Facturas automáticas hechas: {$hechas}.");

        return self::SUCCESS;
    }
}
