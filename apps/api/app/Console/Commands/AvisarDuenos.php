<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\AvisosDuenos;
use Illuminate\Console\Command;

/**
 * Avisos de la plataforma a los dueños (ADR 0071): arma los que tocan (prueba por
 * terminar, renta lista, renta vencida, pago recibido) y los manda por correo y, si
 * el dueño lo aceptó, por WhatsApp. Idempotente: correrlo de nuevo no repite nada.
 */
class AvisarDuenos extends Command
{
    protected $signature = 'agendauno:avisar-duenos';

    protected $description = 'Avisa a los dueños de su prueba y su renta (correo y WhatsApp)';

    public function handle(AvisosDuenos $avisos): int
    {
        $nuevos = $avisos->generar();
        $enviados = $avisos->entregar();

        $this->info("Avisos a dueños: {$nuevos} nuevos, {$enviados} enviados.");

        return self::SUCCESS;
    }
}
