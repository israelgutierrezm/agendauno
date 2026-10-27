<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Comunicaciones\Push\CifradoWebPush;
use Illuminate\Console\Command;

/**
 * Genera las llaves VAPID del push en la web (ADR 0225). Se muestran una sola vez: van
 * en `api.env` (PUSH_WEB_PUBLICA y PUSH_WEB_PRIVADA) y la privada también se guarda
 * fuera del servidor. Cambiarlas deja sin avisos a los navegadores ya suscritos hasta
 * que vuelvan a activarlos.
 */
class GenerarVapid extends Command
{
    protected $signature = 'agendauno:generar-vapid';

    protected $description = 'Genera las llaves VAPID del push en la web';

    public function handle(): int
    {
        $llaves = CifradoWebPush::generarLlaves();
        $this->line('PUSH_WEB_PUBLICA='.$llaves['publica']);
        $this->line('PUSH_WEB_PRIVADA='.$llaves['privada']);
        $this->warn('Guárdalas en api.env; la privada no se vuelve a mostrar.');

        return self::SUCCESS;
    }
}
