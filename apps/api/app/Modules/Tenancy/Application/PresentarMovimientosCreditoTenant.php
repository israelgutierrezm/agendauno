<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\OrigenMovimiento;
use App\Modules\Tenancy\Creditos\TipoMovimiento;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use Illuminate\Support\Collection;

/**
 * El historial de créditos en palabras (fase 1, punto 1.4): por qué cambió el saldo
 * ("Asistencia", "Cancelación tardía", "Créditos vencidos"…) y, si el movimiento
 * viene de una reserva, de qué clase o cita. Lo usan el alumno y el equipo.
 */
class PresentarMovimientosCreditoTenant
{
    /**
     * @param  Collection<int, MovimientoCreditoTenant>  $movimientos
     * @return list<array<string, mixed>>
     */
    public function presentar(Collection $movimientos): array
    {
        $ulids = $movimientos
            ->filter(fn (MovimientoCreditoTenant $m): bool => $m->referencia_tipo === 'reserva' && $m->referencia_id !== null)
            ->pluck('referencia_id')->unique()->values()->all();
        $reservas = ReservaTenant::query()->whereIn('ulid', $ulids)->with('sesion.oferta')->get()->keyBy('ulid');

        return $movimientos->map(function (MovimientoCreditoTenant $m) use ($reservas): array {
            $reserva = $m->referencia_tipo === 'reserva' ? $reservas->get((string) $m->referencia_id) : null;
            $sesion = $reserva?->sesion;

            return [
                'id' => $m->ulid,
                'fecha' => $m->created_at?->toIso8601String(),
                'tipo' => $m->tipo->value,
                'unidades' => $m->unidades,
                'saldo_posterior' => $m->saldo_posterior,
                'concepto' => $this->concepto($m),
                'clase' => $sesion !== null ? [
                    'nombre' => $sesion->oferta?->nombre,
                    'inicia_en' => $sesion->inicia_en->toIso8601String(),
                    'zona_horaria' => $sesion->zona_horaria,
                ] : null,
            ];
        })->values()->all();
    }

    public function concepto(MovimientoCreditoTenant $movimiento): string
    {
        $meta = is_array($movimiento->metadata) ? $movimiento->metadata : [];
        if (($meta['correccion'] ?? false) === true) {
            return 'Corrección de asistencia';
        }

        return match ($movimiento->tipo) {
            TipoMovimiento::Concesion => $movimiento->origen === OrigenMovimiento::Ciclo->value ? 'Renovación del plan' : 'Créditos del plan',
            TipoMovimiento::AddOn => 'Créditos adicionales',
            TipoMovimiento::Ajuste => 'Ajuste del negocio',
            TipoMovimiento::Expiracion => 'Créditos vencidos',
            TipoMovimiento::Reverso => $movimiento->origen === OrigenMovimiento::Reembolso->value ? 'Reembolso' : 'Devolución',
            TipoMovimiento::Consumo => match (true) {
                ($meta['motivo'] ?? null) === 'cancelacion_tardia' => 'Cancelación tardía',
                ($meta['asistencia'] ?? null) === 'presente' => 'Asistencia',
                ($meta['asistencia'] ?? null) === 'ausente' => 'No asistió',
                $movimiento->origen === OrigenMovimiento::Ajuste->value => 'Ajuste del negocio',
                default => 'Uso de créditos',
            },
        };
    }
}
