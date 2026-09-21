<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\CobroRecurrenteTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\ConfiguracionPasarelaTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cobra las renovaciones recurrentes de cada estudio operativo: las membresías con
 * `proxima_cobro_en` vencida y los reintentos de morosos, usando la pasarela EN LÍNEA
 * activa del estudio. Un estudio sin pasarela en línea configurada se salta (no hay
 * cobro automático posible). Pensado para correr a diario.
 */
class CobrarSuscripciones extends Command
{
    protected $signature = 'turnouno:cobrar-suscripciones';

    protected $description = 'Cobra las renovaciones recurrentes vencidas y reintenta a los morosos';

    public function handle(CobroRecurrenteTenant $cobro, GestorDeConexionTenant $gestor): int
    {
        $totales = ['cobrados' => 0, 'pendientes' => 0, 'fallidos' => 0];

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$totales, $cobro, $gestor): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $resumen = $gestor->ejecutarEn($estudio, function () use ($cobro): array {
                        // Pasarela en línea activa del estudio (nunca 'manual': no cobraría de verdad).
                        $proveedor = ConfiguracionPasarelaTenant::query()->where('activa', true)->value('proveedor');
                        if (! is_string($proveedor) || $proveedor === '') {
                            return ['cobrados' => 0, 'pendientes' => 0, 'fallidos' => 0];
                        }

                        $vencidas = $cobro->procesarVencidas($proveedor);
                        $reintentos = $cobro->reintentarMorosos($proveedor);

                        return [
                            'cobrados' => $vencidas['cobrados'] + $reintentos['cobrados'],
                            'pendientes' => $vencidas['pendientes'] + $reintentos['pendientes'],
                            'fallidos' => $vencidas['fallidos'] + $reintentos['fallidos'],
                        ];
                    });

                    $totales['cobrados'] += $resumen['cobrados'];
                    $totales['pendientes'] += $resumen['pendientes'];
                    $totales['fallidos'] += $resumen['fallidos'];
                }
            });

        $this->info("Renovaciones cobradas: {$totales['cobrados']}, pendientes: {$totales['pendientes']}, fallidas: {$totales['fallidos']}.");

        return self::SUCCESS;
    }
}
