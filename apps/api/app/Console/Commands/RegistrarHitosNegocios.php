<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\HitosDeNegocio;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Anota los hitos de la puesta en marcha de cada negocio (plan de mejoras C02 y C08)
 * para el embudo de la plataforma: su primera operación real y, si publicó antes de
 * que existiera el registro, desde cuándo cuenta como publicado. Idempotente: un
 * hito anotado no cambia.
 */
class RegistrarHitosNegocios extends Command
{
    protected $signature = 'agendauno:registrar-hitos';

    protected $description = 'Anota la primera operación real de cada negocio (embudo de la plataforma)';

    public function handle(HitosDeNegocio $hitos, GestorDeConexionTenant $gestor): int
    {
        $anotados = 0;

        Estudio::query()
            ->where('es_demo', false)
            ->where(fn ($q) => $q->whereNull('primera_operacion_en')->orWhere(fn ($p) => $p->where('publicado', true)->whereNull('publicado_en')))
            ->chunkById(100, function (Collection $estudios) use (&$anotados, $hitos, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    // Publicó antes de que se anotara: desde hoy (no se inventa la fecha).
                    if ($estudio->publicado && $estudio->publicado_en === null) {
                        $estudio->forceFill(['publicado_en' => now()])->save();
                    }
                    if ($estudio->primera_operacion_en !== null || ! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }
                    $desde = CarbonImmutable::instance($estudio->created_at ?? now());
                    $primera = $gestor->ejecutarAislado($estudio, fn () => $hitos->primeraOperacion($desde), null);
                    if ($primera !== null) {
                        $estudio->forceFill(['primera_operacion_en' => $primera])->save();
                        $anotados++;
                    }
                }
            });

        $this->info("Primeras operaciones anotadas: {$anotados}.");

        return self::SUCCESS;
    }
}
