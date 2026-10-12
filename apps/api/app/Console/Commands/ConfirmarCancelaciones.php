<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\CancelacionSuscripcionesTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Vuelve a pedir y a confirmar con la pasarela las cancelaciones de pagos automáticos
 * que aún no se confirman (ADR 0134), en cada negocio operativo: mientras no se
 * confirmen, la suscripción podría seguir cobrando.
 */
class ConfirmarCancelaciones extends Command
{
    protected $signature = 'agendauno:confirmar-cancelaciones';

    protected $description = 'Confirma con la pasarela las cancelaciones de pagos automáticos pendientes';

    public function handle(CancelacionSuscripcionesTenant $cancelaciones, GestorDeConexionTenant $gestor): int
    {
        $confirmadas = 0;

        Estudio::query()
            // También los suspendidos: su pasarela podría seguir cobrando.
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value, EstadoEstudio::Suspended->value])
            ->chunkById(100, function (Collection $estudios) use (&$confirmadas, $cancelaciones, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $confirmadas += $gestor->ejecutarAislado($estudio, fn (): int => $cancelaciones->reintentarPendientes(), 0);
                }
            });

        $this->info("Cancelaciones confirmadas: {$confirmadas}.");

        return self::SUCCESS;
    }
}
