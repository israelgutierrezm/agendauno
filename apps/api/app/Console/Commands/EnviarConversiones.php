<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\ConversionesPlataforma;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Manda a GA4 y a Meta las conversiones confirmadas (ADR 0143): detecta las del embudo
 * del SaaS, manda las pendientes y reintenta las que se pueden reintentar sin
 * duplicarse. Al final borra los identificadores del navegador que ya vencieron.
 * Corre aparte de la operación: registrar, pagar o reservar nunca esperan a Google ni
 * a Meta.
 */
class EnviarConversiones extends Command
{
    protected $signature = 'agendauno:enviar-conversiones';

    protected $description = 'Manda a GA4 y Meta las conversiones confirmadas (SaaS y negocios)';

    public function handle(ConversionesPlataforma $plataforma): int
    {
        $ahora = CarbonImmutable::now();
        $detectadas = $plataforma->detectar($ahora);
        $procesadas = $plataforma->enviar($ahora);
        $vencidos = $plataforma->vencerIdentificadores($ahora);

        $this->info("Plataforma: {$detectadas} nuevas, {$procesadas} procesadas, {$vencidos} contextos vencidos.");

        return self::SUCCESS;
    }
}
