<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\CancelarFacturaTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pregunta por las cancelaciones de CFDI que esperan al cliente o al SAT en cada
 * estudio (ADR 0180): aceptada, la factura queda cancelada; rechazada o vencida, sigue
 * vigente y se dice por qué.
 */
class ConciliarCancelacionesCfdi extends Command
{
    protected $signature = 'agendauno:conciliar-cancelaciones-cfdi';

    protected $description = 'Aplica lo que respondió el SAT a las cancelaciones de facturas en espera';

    public function handle(CancelarFacturaTenant $cancelar, GestorDeConexionTenant $gestor): int
    {
        $cambiaron = 0;

        Estudio::query()
            // También los suspendidos: el SAT responde aunque el negocio esté suspendido.
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value, EstadoEstudio::Suspended->value])
            ->chunkById(100, function (Collection $estudios) use (&$cambiaron, $cancelar, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $cambiaron += $gestor->ejecutarAislado($estudio, fn (): int => $cancelar->conciliar(), 0);
                }
            });

        $this->info("Cancelaciones resueltas: {$cambiaron}.");

        return self::SUCCESS;
    }
}
