<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Application\GenerarCargoRenta;
use App\Modules\Tenancy\Application\PlanCitasSaas;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Exceptions\TipoCambioNoDisponible;
use App\Modules\Tenancy\Models\Estudio;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Emite los cargos de renta del SaaS (plataforma → dueño) de cada estudio operativo:
 *
 * - MES VENCIDO (clases, cuota fija y las tarifas de citas anteriores): por defecto,
 *   el mes anterior EN LA ZONA HORARIA de cada negocio (el día 1 a las 02:00 UTC en
 *   CDMX aún es el mes anterior). Un periodo que aún no cierra se salta.
 * - POR ADELANTADO (citas con plan, ADR 0107): el periodo del plan que ya empezó.
 *
 * Idempotente: un cargo ya emitido no se toca, así que correrlo a diario solo emite
 * los que falten. Sin tipo de cambio para cobrar en pesos, el cargo espera (se avisa
 * al superadmin) y se emite en la siguiente corrida.
 */
class GenerarCargosRenta extends Command
{
    protected $signature = 'agendauno:generar-cargos-renta {--periodo= : Periodo YYYY-MM (por defecto el mes anterior de cada negocio: cobro mes vencido)}';

    protected $description = 'Emite los cargos de renta del SaaS de los meses ya cerrados y de los planes por adelantado';

    public function handle(GenerarCargoRenta $generar, PlanCitasSaas $planes, GestorDeConexionTenant $gestor): int
    {
        $pedido = (string) ($this->option('periodo') ?? '');
        $emitidos = 0;
        $abiertos = 0;
        $sinTipoCambio = 0;

        Estudio::query()
            ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
            ->chunkById(100, function (Collection $estudios) use (&$emitidos, &$abiertos, &$sinTipoCambio, $generar, $planes, $gestor, $pedido): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    if (! $gestor->baseDeDatosExiste($estudio)) {
                        continue;
                    }

                    try {
                        $periodo = $pedido !== ''
                            ? $pedido
                            : CarbonImmutable::now((string) ($estudio->zona_horaria ?: 'UTC'))->subMonthNoOverflow()->format('Y-m');
                        if (! $generar->cerrado($estudio, $periodo)) {
                            $abiertos++;
                        } elseif (! $generar->porAdelantado($estudio, $periodo)) {
                            $generar->paraEstudio($estudio, $periodo);
                            $emitidos++;
                        }

                        if ($planes->aplica($estudio)) {
                            $emitidos += count($planes->emitirPendientes($estudio));
                        }
                    } catch (TipoCambioNoDisponible) {
                        $sinTipoCambio++;
                    }
                }
            });

        $this->info("Cargos de renta al día: {$emitidos}."
            .($abiertos > 0 ? " Periodos aún abiertos (se saltaron): {$abiertos}." : '')
            .($sinTipoCambio > 0 ? " Sin tipo de cambio (esperan): {$sinTipoCambio}." : ''));

        return self::SUCCESS;
    }
}
