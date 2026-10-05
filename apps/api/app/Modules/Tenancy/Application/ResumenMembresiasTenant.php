<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\EstadoRetencion;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\RetencionCreditoTenant;

/**
 * Estado de la membresía o paquete de varias personas a la vez: el resumen de una
 * persona (Recepción, ficha) y las tarjetas del listado usan la misma regla. Pocas
 * consultas por lote, no una por persona.
 *
 * Estado explicable: `sin` / `vigente` / `por_vencer` / `pausada` / `vencida`. El
 * saldo es el disponible (ledger − retenciones activas) de lo vigente.
 */
final class ResumenMembresiasTenant
{
    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    /**
     * @param  list<int>  $personaIds
     * @return array<int, array{estado: string, plan: string|null, valido_hasta: string|null, pausada_hasta: string|null, ilimitado: bool, saldo_unidades: int, tiene_acceso: bool, planes: list<string>}>
     */
    public function deVarias(array $personaIds): array
    {
        $resultado = [];
        foreach ($personaIds as $id) {
            $resultado[$id] = [
                'estado' => 'sin', 'plan' => null, 'valido_hasta' => null, 'pausada_hasta' => null,
                'ilimitado' => false, 'saldo_unidades' => 0, 'tiene_acceso' => false, 'planes' => [],
            ];
        }
        if ($personaIds === []) {
            return $resultado;
        }

        $derechos = DerechoTenant::query()
            ->whereHas('acuerdo', fn ($q) => $q->whereIn('persona_id', $personaIds))
            ->with(['acuerdo.pausaAbierta', 'acuerdo.producto'])
            ->orderByDesc('id')
            ->get();
        $ids = $derechos->modelKeys();
        $saldos = MovimientoCreditoTenant::query()
            ->whereIn('derecho_id', $ids)
            ->groupBy('derecho_id')
            ->selectRaw('derecho_id, SUM(unidades) as total')
            ->pluck('total', 'derecho_id');
        $retenidos = RetencionCreditoTenant::query()
            ->whereIn('derecho_id', $ids)
            ->where('estado', EstadoRetencion::Activa->value)
            ->groupBy('derecho_id')
            ->selectRaw('derecho_id, SUM(unidades) as total')
            ->pluck('total', 'derecho_id');

        // Hoy en el negocio (no en UTC): un plan que vence hoy sigue vigente hasta la noche.
        $hoy = $this->fechas->dia();
        $porVencer = $hoy->addDays($this->parametros->entero('membresias.dias_por_vencer'));

        foreach ($derechos->groupBy(fn (DerechoTenant $d): int => (int) $d->acuerdo?->persona_id) as $personaId => $lista) {
            $tieneAcceso = false;
            $ilimitado = false;
            $plan = null;
            // Todos los planes que hoy le dan acceso (puede tener más de uno).
            $planes = [];
            $pausadaHasta = null;
            $maxVigencia = null;
            $saldo = 0;
            foreach ($lista as $derecho) {
                $vence = $derecho->valido_hasta;
                $vigente = $vence === null || $vence->gte($hoy);
                $disponible = $derecho->ilimitado
                    ? 0
                    : (int) ($saldos[$derecho->getKey()] ?? 0) - (int) ($retenidos[$derecho->getKey()] ?? 0);

                // Solo una membresía activa da acceso (en pausa o suspendida, no).
                $activo = $derecho->acuerdo?->estado === EstadoAcuerdo::Activo;
                if ($activo && $vigente && ($derecho->ilimitado || $disponible > 0)) {
                    $tieneAcceso = true;
                    $ilimitado = $ilimitado || $derecho->ilimitado;
                    $plan ??= $derecho->acuerdo->producto?->nombre;
                    $planes[] = (string) $derecho->acuerdo->producto?->nombre;
                }
                $pausa = $derecho->acuerdo?->pausaAbierta;
                if ($pausa !== null && ($pausadaHasta === null || $pausa->hasta->gt($pausadaHasta))) {
                    $pausadaHasta = $pausa->hasta;
                }
                if (! $derecho->ilimitado && $vigente) {
                    $saldo += max(0, $disponible);
                }
                if ($vence !== null && ($maxVigencia === null || $vence->gt($maxVigencia))) {
                    $maxVigencia = $vence;
                }
            }

            $estado = 'sin';
            if ($tieneAcceso) {
                $estado = $maxVigencia !== null && $maxVigencia->lte($porVencer) ? 'por_vencer' : 'vigente';
            } elseif ($pausadaHasta !== null) {
                $estado = 'pausada';
            } elseif ($maxVigencia !== null && $maxVigencia->lt($hoy)) {
                $estado = 'vencida';
            }

            $resultado[$personaId] = [
                'estado' => $estado,
                // Sin acceso: la más reciente (p. ej. "Mensualidad" vencida o en pausa).
                'plan' => $plan ?? $lista->first()?->acuerdo?->producto?->nombre,
                'valido_hasta' => $maxVigencia?->toDateString(),
                'pausada_hasta' => $pausadaHasta?->toDateString(),
                'ilimitado' => $ilimitado,
                'saldo_unidades' => $saldo,
                'tiene_acceso' => $tieneAcceso,
                'planes' => array_values(array_unique(array_filter($planes))),
            ];
        }

        return $resultado;
    }
}
