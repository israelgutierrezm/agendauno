<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\PublicarEnPlataformasTenant;
use App\Modules\Tenancy\Application\ReservasPlataformaTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\HorarioPlataformaTenant;
use App\Modules\Tenancy\Models\IntegracionTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Mantiene al día las clases publicadas en Wellhub y TotalPass de cada negocio (ADR
 * 0149): publica las nuevas, manda los lugares que cambiaron, retira lo que ya no se
 * publica, deja como «no asistió» las reservas de la plataforma de clases que ya
 * terminaron sin check-in y concilia las reservas de TotalPass con las que él tiene. Como los avisos, sigue en un negocio suspendido: sus clases
 * siguen en pie.
 */
class SincronizarPlataformas extends Command
{
    protected $signature = 'agendauno:sincronizar-plataformas';

    protected $description = 'Sincroniza las clases publicadas en Wellhub y TotalPass de cada negocio';

    public function handle(PublicarEnPlataformasTenant $publicar, ReservasPlataformaTenant $reservas, GestorDeConexionTenant $gestor): int
    {
        $cambios = 0;
        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value, EstadoEstudio::Suspended->value])
            ->chunkById(100, function (Collection $estudios) use (&$cambios, $publicar, $reservas, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }
                    $cambios += $gestor->ejecutarAislado($estudio, function () use ($publicar, $reservas): int {
                        // Sin la migración aún, o sin nada que publicar ni publicado, no hace nada.
                        if (! Schema::connection('tenant')->hasTable('horarios_plataforma')
                            || (! IntegracionTenant::query()->where('reservas_activas', true)->exists()
                                && ! HorarioPlataformaTenant::query()->where('estado', '!=', HorarioPlataformaTenant::RETIRADO)->exists())) {
                            return 0;
                        }
                        $reservas->cerrarTerminadas();
                        // TotalPass no firma sus avisos ni dice si reintenta: se concilia.
                        $reservas->conciliarTotalPass();

                        return $publicar->sincronizar();
                    }, 0);
                }
            });
        // Un negocio dado de baja ya no se reserva desde ninguna plataforma (ADR 0202).
        Estudio::query()
            ->where('estado', EstadoEstudio::Cancelled->value)
            ->chunkById(100, function (Collection $estudios) use (&$cambios, $publicar, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }
                    $cambios += $gestor->ejecutarAislado($estudio, function () use ($publicar): int {
                        if (! Schema::connection('tenant')->hasTable('horarios_plataforma')
                            || ! HorarioPlataformaTenant::query()->where('estado', '!=', HorarioPlataformaTenant::RETIRADO)->exists()) {
                            return 0;
                        }

                        return $publicar->retirarTodo();
                    }, 0);
                }
            });
        $this->info("Horarios publicados o actualizados: {$cambios}.");

        return self::SUCCESS;
    }
}
