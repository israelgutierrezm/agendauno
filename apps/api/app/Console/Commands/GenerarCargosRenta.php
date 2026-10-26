<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Tenancy\Application\ComplementosRentaSaas;
use App\Modules\Tenancy\Application\CondicionesDeCobroSaas;
use App\Modules\Tenancy\Application\GenerarCargoRenta;
use App\Modules\Tenancy\Application\PlanCitasSaas;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\EstadoEstudio;
use App\Modules\Tenancy\Exceptions\TipoCambioNoDisponible;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

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
 * al superadmin) y se emite en la siguiente corrida. Un negocio que falla (su base no
 * responde, un dato roto) se reporta y se avisa al superadmin, sin detener a los demás.
 */
class GenerarCargosRenta extends Command
{
    protected $signature = 'agendauno:generar-cargos-renta {--periodo= : Periodo YYYY-MM (por defecto el mes anterior de cada negocio: cobro mes vencido)}';

    protected $description = 'Emite los cargos de renta del SaaS de los meses ya cerrados y de los planes por adelantado';

    public function handle(GenerarCargoRenta $generar, PlanCitasSaas $planes, GestorDeConexionTenant $gestor, AlertasPlataforma $alertas, CondicionesDeCobroSaas $condiciones, ComplementosRentaSaas $complementos): int
    {
        $pedido = (string) ($this->option('periodo') ?? '');
        $emitidos = 0;
        $abiertos = 0;
        $sinTipoCambio = 0;
        $fallidos = 0;

        Estudio::query()
            // También el dado de baja que aún tiene sus datos: su último mes se cobra
            // (hasta el de la baja, ADR 0213).
            ->where(static fn ($q) => $q
                ->whereIn('estado', [EstadoEstudio::Trialing->value, EstadoEstudio::Active->value])
                ->orWhere(static fn ($b) => $b->where('estado', EstadoEstudio::Cancelled->value)
                    ->whereNotNull('dado_de_baja_en')->whereNull('datos_borrados_en')))
            ->chunkById(100, function (Collection $estudios) use (&$emitidos, &$abiertos, &$sinTipoCambio, &$fallidos, $generar, $planes, $gestor, $alertas, $condiciones, $complementos, $pedido): void {
                /** @var Collection<int, Estudio> $estudios */
                foreach ($estudios as $estudio) {
                    try {
                        // Lo pactado para este mes, si cambió (ADR 0181).
                        $condiciones->sincronizar($estudio);
                        if (! $gestor->baseDeDatosExiste($estudio)) {
                            continue;
                        }
                        // Sin periodo pedido: el mes anterior y los cerrados que hayan
                        // faltado (p. ej. mientras estuvo suspendido).
                        $periodos = $pedido !== '' ? [$pedido] : $generar->periodosPorEmitir($estudio);
                        foreach ($periodos as $periodo) {
                            if (! $generar->cerrado($estudio, $periodo)) {
                                $abiertos++;

                                continue;
                            }
                            if (! $generar->porAdelantado($estudio, $periodo)) {
                                $generar->paraEstudio($estudio, $periodo);
                                $emitidos++;
                            }
                            // La app con su marca, aparte de la renta (ADR 0188).
                            if ($complementos->emitirMensualidades($estudio, $periodo)?->wasRecentlyCreated === true) {
                                $emitidos++;
                            }
                        }

                        // Dado de baja, su plan por adelantado ya no se renueva.
                        if ($estudio->estado !== EstadoEstudio::Cancelled && $planes->aplica($estudio)) {
                            $emitidos += count($planes->emitirPendientes($estudio));
                        }
                    } catch (TipoCambioNoDisponible) {
                        $sinTipoCambio++;
                    } catch (Throwable $e) {
                        $fallidos++;
                        $alertas->registrarExcepcion('renta', 'emitir-'.$estudio->slug, $e, (string) $estudio->slug);
                        report($e);
                    }
                }
            });

        $this->info("Cargos de renta al día: {$emitidos}."
            .($abiertos > 0 ? " Periodos aún abiertos (se saltaron): {$abiertos}." : '')
            .($sinTipoCambio > 0 ? " Sin tipo de cambio (esperan): {$sinTipoCambio}." : '')
            .($fallidos > 0 ? " Negocios con error (se reintentan en la siguiente corrida): {$fallidos}." : ''));

        return self::SUCCESS;
    }
}
