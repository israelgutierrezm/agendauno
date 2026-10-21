<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Bandeja\BandejaTenant;
use App\Modules\Tenancy\Models\CanalMensajeria;
use Illuminate\Console\Command;

/**
 * Renueva las llaves de Instagram de la bandeja (ADR 0200): duran 60 días y se renuevan
 * las que vencen en menos de 15. La que ya no sirve deja la cuenta en «reconectar».
 */
class RenovarInstagram extends Command
{
    private const DIAS_ANTES = 15;

    protected $signature = 'agendauno:renovar-instagram';

    protected $description = 'Renueva las llaves de Instagram de la bandeja de cada negocio antes de que venzan';

    public function handle(BandejaTenant $bandeja): int
    {
        $renovadas = 0;
        CanalMensajeria::query()
            ->where('canal', CanalMensajeria::INSTAGRAM)
            ->where('estado', 'activo')
            ->where(fn ($q) => $q->whereNull('token_vence_en')->orWhere('token_vence_en', '<', now()->addDays(self::DIAS_ANTES)))
            ->each(function (CanalMensajeria $canal) use ($bandeja, &$renovadas): void {
                $renovadas += $bandeja->renovar($canal) ? 1 : 0;
            });
        $this->info("Llaves de Instagram renovadas: {$renovadas}.");

        return self::SUCCESS;
    }
}
