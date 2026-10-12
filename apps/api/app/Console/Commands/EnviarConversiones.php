<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\ConversionesNegocioTenant;
use App\Modules\Tenancy\Application\ConversionesPlataforma;
use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Manda a GA4 y a Meta las conversiones confirmadas (ADR 0143): detecta las del embudo
 * del SaaS y las manda, y luego, negocio por negocio (cada uno en su base y aislado:
 * si uno falla, los demás siguen), manda las suyas. Reintenta las que se pueden
 * reintentar sin duplicarse y borra los identificadores del navegador que ya
 * vencieron. Corre aparte de la operación: registrar, pagar o reservar nunca esperan a
 * Google ni a Meta.
 */
class EnviarConversiones extends Command
{
    protected $signature = 'agendauno:enviar-conversiones';

    protected $description = 'Manda a GA4 y Meta las conversiones confirmadas (SaaS y negocios)';

    public function handle(ConversionesPlataforma $plataforma, ConversionesNegocioTenant $negocios, GestorDeConexionTenant $gestor, FuncionesPlan $funciones): int
    {
        $ahora = CarbonImmutable::now();
        $detectadas = $plataforma->detectar($ahora);
        $procesadas = $plataforma->enviar($ahora);
        $vencidos = $plataforma->vencerIdentificadores($ahora);
        $this->info("Plataforma: {$detectadas} nuevas, {$procesadas} procesadas, {$vencidos} contextos vencidos.");

        $deNegocios = 0;
        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$deNegocios, $negocios, $gestor, $funciones, $ahora): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }
                    $deNegocios += $gestor->ejecutarAislado($estudio, function () use ($negocios, $funciones, $estudio, $ahora): int {
                        $negocios->vencerIdentificadores($ahora);

                        // Sin integraciones en su plan, lo suyo espera (y vence con la ventana).
                        return $funciones->tiene($estudio, 'integraciones') ? $negocios->enviar($ahora) : 0;
                    }, 0);
                }
            });
        $this->info("Negocios: {$deNegocios} procesadas.");

        return self::SUCCESS;
    }
}
