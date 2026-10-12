<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Avisa al equipo de la plataforma algo del despliegue (ADR 0135), p. ej. que una
 * versión falló y se volvió sola a la anterior: queda como alerta y se manda en el
 * acto al correo de alertas (no por la cola, que podría ser lo que falla).
 */
class AvisarDespliegue extends Command
{
    protected $signature = 'agendauno:avisar-despliegue {mensaje : Qué pasó}';

    protected $description = 'Manda al correo de alertas de la plataforma un aviso del despliegue';

    public function handle(AlertasPlataforma $alertas): int
    {
        $mensaje = trim((string) $this->argument('mensaje'));
        if ($mensaje === '') {
            $this->error('Falta el mensaje.');

            return self::INVALID;
        }
        // Cada aviso es uno (no se agrupa con el de otro despliegue).
        $alertas->registrar('despliegue', 'despliegue:'.CarbonImmutable::now()->toIso8601String(), $mensaje);

        return $this->call('agendauno:enviar-alertas');
    }
}
