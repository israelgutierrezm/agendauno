<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Platform\Operacion\RespaldosPlataforma;
use Illuminate\Console\Command;
use Throwable;

/**
 * Respalda la base central de la plataforma y los archivos subidos (documentos,
 * fotos, logos) en el disco de respaldos (RESPALDOS_DISCO; en producción, fuera del
 * servidor) y borra los viejos. Si algo falla, avisa al superadmin y sale con error
 * (actualizar.sh no migra sin este respaldo).
 */
class RespaldarPlataforma extends Command
{
    protected $signature = 'agendauno:respaldar-plataforma
        {--sin-archivos : Solo la base central (p. ej. justo antes de actualizar)}
        {--dias= : Días que se conservan (por defecto RESPALDOS_DIAS)}';

    protected $description = 'Respalda la base central de la plataforma y los archivos subidos';

    public function handle(RespaldosPlataforma $respaldos, AlertasPlataforma $alertas): int
    {
        try {
            $this->info('Plataforma: '.$respaldos->respaldarPlataforma());
            if (! $this->option('sin-archivos')) {
                $this->info('Archivos: '.$respaldos->respaldarArchivos());
            }
            $dias = (int) ($this->option('dias') ?? config('agendauno.respaldos.dias', 14));
            $borrados = $respaldos->limpiar(max(1, $dias));
            if ($borrados > 0) {
                $this->line("Respaldos viejos borrados: {$borrados}.");
            }
        } catch (Throwable $e) {
            $alertas->registrarExcepcion('respaldo_fallido', 'plataforma', $e);
            report($e);
            $this->error('No se pudo respaldar la plataforma: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
