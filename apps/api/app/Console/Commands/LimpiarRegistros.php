<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\LimpiezaDeRegistros;
use Illuminate\Console\Command;

/**
 * Borra los registros técnicos que ya cumplieron su función, con los plazos de los
 * parámetros de plataforma (ADR 0079).
 */
class LimpiarRegistros extends Command
{
    protected $signature = 'agendauno:limpiar-registros';

    protected $description = 'Borra los registros técnicos vencidos (envíos y códigos de WhatsApp, sesiones de tarjeta, errores viejos)';

    public function handle(LimpiezaDeRegistros $limpieza): int
    {
        $borrados = $limpieza->ejecutar();

        $this->info("Envíos de WhatsApp: {$borrados['envios_whatsapp']}. Códigos de verificación: {$borrados['verificaciones_whatsapp']}. Sesiones de tarjeta: {$borrados['sesiones_tarjeta']}. Errores: {$borrados['errores']}.");

        return self::SUCCESS;
    }
}
