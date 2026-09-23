<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Creditos\OrigenMovimiento;
use App\Modules\Creditos\TipoMovimiento;
use App\Modules\Membresias\Application\GenerarCicloEntitlement;
use App\Modules\Membresias\EstadoAcuerdo;
use App\Modules\Membresias\PoliticaReset;
use App\Modules\Membresias\PoliticaRollover;
use App\Modules\Tenancy\Models\DerechoTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Avanza los ciclos vencidos de un derecho recurrente TENANT-LOCAL: al cerrar cada
 * ciclo aplica el rollover (lo no acarreado se EXPIRA en el ledger), abre el ciclo
 * siguiente y concede su cupo. Idempotente (solo avanza si el ciclo actual venció) y
 * con `lockForUpdate` en la conexión del tenant. Es la versión por-estudio de
 * {@see GenerarCicloEntitlement}; antes ese motor
 * solo operaba sobre el esquema legacy, por lo que las membresías recurrentes NO se
 * renovaban en el plano activo.
 */
class GenerarCicloEntitlementTenant
{
    // Tope defensivo de ciclos a avanzar en una corrida (evita bucles patológicos).
    private const MAX_CICLOS = 120;

    public function __construct(private readonly LibroMayorTenant $libro) {}

    public function ejecutar(DerechoTenant $derecho): int
    {
        if ($derecho->politica_reset === PoliticaReset::Ninguno || $derecho->ciclo_fin === null) {
            return 0;
        }

        // No renovar ciclos de acuerdos que ya no están activos (F-13).
        if ($derecho->acuerdo->estado !== EstadoAcuerdo::Activo) {
            return 0;
        }

        $hoy = Carbon::now()->startOfDay();

        return DB::connection('tenant')->transaction(function () use ($derecho, $hoy): int {
            $bloqueado = DerechoTenant::query()->whereKey($derecho->getKey())->lockForUpdate()->firstOrFail();
            $ciclos = 0;

            while ($ciclos < self::MAX_CICLOS
                && $bloqueado->ciclo_fin !== null
                && $bloqueado->ciclo_fin->lt($hoy)) {
                $saldo = $this->libro->saldo($bloqueado);
                $acarreo = $this->rollover($bloqueado, $saldo);
                $expira = $saldo - $acarreo;

                if ($expira > 0) {
                    $this->libro->registrar($bloqueado, TipoMovimiento::Expiracion, -$expira, 'Expiracion de ciclo', ContextoMovimiento::para(OrigenMovimiento::Ciclo));
                }

                [$inicio, $fin] = $this->siguienteVentana($bloqueado);
                $bloqueado->update(['ciclo_inicio' => $inicio, 'ciclo_fin' => $fin]);

                $cupo = $bloqueado->unidades_por_ciclo ?? 0;
                if ($cupo > 0) {
                    $this->libro->registrar($bloqueado, TipoMovimiento::Concesion, $cupo, 'Concesión de ciclo', ContextoMovimiento::para(OrigenMovimiento::Ciclo));
                }

                $bloqueado->refresh();
                $ciclos++;
            }

            return $ciclos;
        });
    }

    private function rollover(DerechoTenant $derecho, int $saldo): int
    {
        return match ($derecho->politica_rollover) {
            PoliticaRollover::Completo => $saldo,
            PoliticaRollover::Limitado => min($saldo, $derecho->rollover_max ?? 0),
            PoliticaRollover::Ninguno => 0,
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function siguienteVentana(DerechoTenant $derecho): array
    {
        $fin = $derecho->ciclo_fin;
        $siguiente = $fin instanceof Carbon ? $fin->copy()->addDay() : Carbon::now();

        if ($derecho->politica_reset === PoliticaReset::Calendario) {
            $inicio = $siguiente->copy()->startOfMonth();

            return [$inicio->toDateString(), $inicio->copy()->endOfMonth()->toDateString()];
        }

        return [$siguiente->toDateString(), $siguiente->copy()->addMonth()->subDay()->toDateString()];
    }
}
