<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Platform\Operacion\PendientesDeNegocios;
use Illuminate\Console\Command;

/**
 * Mide lo que espera en la base de cada negocio (outbox y avisos de pasarela), deja el
 * dato para agendauno:verificar-produccion y alerta lo atascado (ADR 0232). Lo aparcado y
 * lo fallido ya alertó al pasar.
 */
class RevisarPendientes extends Command
{
    protected $signature = 'agendauno:revisar-pendientes';

    protected $description = 'Mide los eventos y avisos pendientes de cada negocio y alerta lo atascado';

    public function handle(PendientesDeNegocios $pendientes, AlertasPlataforma $alertas): int
    {
        $medido = $pendientes->medir();
        $atrasados = $medido['outbox']['atrasados'];
        $atascados = $medido['avisos']['atascados'];
        $this->info("Eventos atrasados: {$atrasados}. Avisos atascados: {$atascados}.");
        if ($atrasados + $atascados > 0) {
            $negocios = array_unique([...$medido['outbox']['negocios'], ...$medido['avisos']['negocios']]);
            $alertas->registrar('pendientes_atascados', 'negocios',
                "{$atrasados} evento(s) del outbox y {$atascados} aviso(s) de pasarela llevan mucho sin procesarse. En: ".implode(', ', $negocios).'. Revisa el programador.');
        }

        return self::SUCCESS;
    }
}
