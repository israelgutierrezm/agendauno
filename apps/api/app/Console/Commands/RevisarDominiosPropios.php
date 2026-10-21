<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\DominiosPropios;
use App\Modules\Tenancy\Models\DominioEstudio;
use Illuminate\Console\Command;

/**
 * Revisa el DNS de los dominios propios (ADR 0189): los pendientes, por si su dueño ya
 * creó los registros, y los activos cada 6 horas, por si dejaron de apuntar. Luego deja
 * al día la configuración de Traefik con los que se sirven.
 */
class RevisarDominiosPropios extends Command
{
    protected $signature = 'agendauno:dominios-propios';

    protected $description = 'Revisa el DNS de los dominios propios y actualiza la configuración de Traefik';

    public function handle(DominiosPropios $dominios): int
    {
        $revisados = 0;
        DominioEstudio::query()
            ->where(fn ($q) => $q->whereNull('propiedad_verificada_en')
                ->orWhereNull('apunta_desde')
                ->orWhereNull('revisado_en')
                ->orWhere('revisado_en', '<', now()->subHours(6)))
            ->with('estudio')
            ->chunkById(100, function ($pendientes) use ($dominios, &$revisados): void {
                foreach ($pendientes as $dominio) {
                    try {
                        $dominios->revisar($dominio);
                        $revisados++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        $escrito = $dominios->escribirTraefik();
        $this->info("Dominios revisados: {$revisados}.".($escrito ? ' Configuración de Traefik actualizada.' : ''));

        return self::SUCCESS;
    }
}
