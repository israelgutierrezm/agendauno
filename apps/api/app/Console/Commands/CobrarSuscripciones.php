<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\CobroRecurrenteTenant;
use App\Modules\Tenancy\Application\ConciliarSuscripcionTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Pasarelas\RegistroDePasarelasTenant;
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
    protected $signature = 'agendauno:cobrar-suscripciones';

    protected $description = 'Cobra las renovaciones recurrentes vencidas y reintenta a los morosos';

    public function handle(CobroRecurrenteTenant $cobro, GestorDeConexionTenant $gestor, RegistroDePasarelasTenant $pasarelas, ConciliarSuscripcionTenant $suscripciones): int
    {
        $totales = ['cobrados' => 0, 'pendientes' => 0, 'fallidos' => 0];

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$totales, $cobro, $gestor, $pasarelas, $suscripciones): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    $resumen = $gestor->ejecutarAislado($estudio, function () use ($cobro, $pasarelas, $suscripciones): array {
                        // Lo que cobró una suscripción que se cancela o ya se canceló
                        // (ADR 0134): aunque el negocio ya no cobre en línea.
                        $suscripciones->conciliarCanceladas();

                        // Pasarela en línea lista del estudio (nunca manual ni ventanilla: no
                        // cobrarían de verdad).
                        $proveedor = $pasarelas->enLinea();
                        if ($proveedor === null) {
                            return ['cobrados' => 0, 'pendientes' => 0, 'fallidos' => 0];
                        }

                        $vencidas = $cobro->procesarVencidas($proveedor);
                        $reintentos = $cobro->reintentarMorosos($proveedor);

                        return [
                            'cobrados' => $vencidas['cobrados'] + $reintentos['cobrados'],
                            'pendientes' => $vencidas['pendientes'] + $reintentos['pendientes'],
                            'fallidos' => $vencidas['fallidos'] + $reintentos['fallidos'],
                        ];
                    }, ['cobrados' => 0, 'pendientes' => 0, 'fallidos' => 0]);

                    $totales['cobrados'] += $resumen['cobrados'];
                    $totales['pendientes'] += $resumen['pendientes'];
                    $totales['fallidos'] += $resumen['fallidos'];
                }
            });

        $this->info("Renovaciones cobradas: {$totales['cobrados']}, pendientes: {$totales['pendientes']}, fallidas: {$totales['fallidos']}.");

        return self::SUCCESS;
    }
}
