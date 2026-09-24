<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\EstadoRetencion;
use App\Modules\Tenancy\Creditos\TipoMovimiento;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;

/**
 * Ledger de creditos tenant-local. Asienta movimientos y deriva el saldo de un
 * derecho como la suma de sus movimientos (fuente de verdad auditable, nunca un
 * saldo guardado). Opera sobre la BD del tenant resuelto.
 */
class LibroMayorTenant
{
    /**
     * Asienta un movimiento en el ledger dejando rastro auditable: de quién es
     * (`persona_id`, derivado del acuerdo), el saldo resultante (`saldo_posterior`,
     * instantánea para conciliar) y el {@see ContextoMovimiento} (origen, referencia,
     * actor, metadata). El saldo previo se lee dentro del lock que el llamador ya
     * mantiene sobre el derecho, por lo que la instantánea es consistente.
     */
    public function registrar(
        DerechoTenant $derecho,
        TipoMovimiento $tipo,
        int $unidades,
        ?string $descripcion = null,
        ?ContextoMovimiento $contexto = null,
    ): MovimientoCreditoTenant {
        $saldoPrevio = $this->saldo($derecho);
        $actor = $contexto?->actor;

        return $derecho->movimientos()->create([
            'persona_id' => $derecho->acuerdo?->persona_id,
            'tipo' => $tipo,
            'origen' => $contexto?->origen?->value,
            'unidades' => $unidades,
            'saldo_posterior' => $saldoPrevio + $unidades,
            'descripcion' => $descripcion,
            'referencia_tipo' => $contexto?->referenciaTipo,
            'referencia_id' => $contexto?->referenciaId,
            'actor_id' => $actor?->getKey(),
            'actor_nombre' => $actor?->name,
            'metadata' => $contexto?->metadata,
        ]);
    }

    public function saldo(DerechoTenant $derecho): int
    {
        return (int) $derecho->movimientos()->sum('unidades');
    }

    /**
     * Disponible = saldo del ledger − retenciones (holds) activas.
     */
    public function disponible(DerechoTenant $derecho): int
    {
        $retenido = (int) $derecho->retenciones()
            ->where('estado', EstadoRetencion::Activa)
            ->sum('unidades');

        return $this->saldo($derecho) - $retenido;
    }
}
