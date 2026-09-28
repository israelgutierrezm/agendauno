<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Application\RespaldosEstudio;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Throwable;

/**
 * Respalda la base de cada negocio (o de uno con --estudio) y borra los respaldos
 * más viejos que la retención. Un negocio que falla no frena a los demás.
 */
class RespaldarEstudios extends Command
{
    protected $signature = 'agendauno:respaldar-estudios {--estudio= : slug de un solo negocio} {--dias= : días que se conservan (por defecto la configuración)}';

    protected $description = 'Respalda la base de datos de cada negocio y aplica la retención';

    public function handle(RespaldosEstudio $respaldos, GestorDeConexionTenant $gestor): int
    {
        $dias = (int) ($this->option('dias') ?: config('agendauno.respaldos.dias', 14));
        $fallas = 0;

        $estudios = Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value, EstadoEstudio::Suspended->value])
            ->when($this->option('estudio'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')
            ->get();

        foreach ($estudios as $estudio) {
            if (! $gestor->baseDeDatosExiste($estudio)) {
                continue;
            }

            try {
                $ruta = $respaldos->respaldar($estudio);
                $borrados = $respaldos->limpiar($estudio, $dias);
                $this->line("{$estudio->slug}: {$ruta}".($borrados > 0 ? " ({$borrados} viejos borrados)" : ''));
            } catch (Throwable $e) {
                $fallas++;
                app(AlertasPlataforma::class)->registrarExcepcion('respaldo_fallido', $estudio->slug, $e, $estudio->slug);
                report($e);
                $this->error("{$estudio->slug}: {$e->getMessage()}");
            }
        }

        return $fallas === 0 ? self::SUCCESS : self::FAILURE;
    }
}
