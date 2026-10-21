<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\PlantillasWhatsAppNegocio;
use App\Modules\Tenancy\Models\PlantillaWhatsAppNegocio;
use Illuminate\Console\Command;

/**
 * Pregunta a Meta cómo van las plantillas de los negocios (ADR 0190): las que están en
 * revisión, y las aprobadas o pausadas una vez al día (Meta pausa o desactiva las de
 * baja calidad).
 */
class RevisarPlantillasWhatsApp extends Command
{
    protected $signature = 'agendauno:revisar-plantillas-whatsapp';

    protected $description = 'Actualiza el estado en Meta de las plantillas de WhatsApp de los negocios';

    public function handle(PlantillasWhatsAppNegocio $plantillas): int
    {
        $revisadas = 0;
        PlantillaWhatsAppNegocio::query()
            ->where(fn ($q) => $q->where('estado', 'en_revision')
                ->orWhere(fn ($q) => $q->whereIn('estado', ['aprobada', 'pausada'])
                    ->where(fn ($q) => $q->whereNull('revisada_en')->orWhere('revisada_en', '<', now()->subDay()))))
            ->with('estudio')
            ->chunkById(100, function ($pendientes) use ($plantillas, &$revisadas): void {
                foreach ($pendientes as $plantilla) {
                    try {
                        $plantillas->sincronizar($plantilla);
                        $revisadas++;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });

        $this->info("Plantillas revisadas: {$revisadas}.");

        return self::SUCCESS;
    }
}
