<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Resuelve que derecho (entitlement) tenant-local de una persona cubre una sesion:
 * uno vigente y con saldo suficiente. Prefiere gastar un derecho limitado con saldo
 * antes que uno ilimitado, para no "desperdiciar" packs comprados.
 */
class ResolverDerechoTenant
{
    public function __construct(private readonly LibroMayorTenant $libro) {}

    public function paraSesion(PersonaTenant $persona, SesionTenant $sesion, int $unidades): ?DerechoTenant
    {
        $derechos = DerechoTenant::query()
            ->whereHas('acuerdo', function (Builder $consulta) use ($persona): void {
                $consulta->where('persona_id', $persona->getKey())
                    ->where('estado', EstadoAcuerdo::Activo->value);
            })
            ->get()
            ->filter(fn (DerechoTenant $derecho): bool => $this->vigente($derecho, $sesion->inicia_en) && $this->cubre($derecho, $sesion));

        $limitado = $derechos->first(
            fn (DerechoTenant $derecho): bool => ! $derecho->ilimitado && $this->libro->disponible($derecho) >= $unidades,
        );

        if ($limitado !== null) {
            return $limitado;
        }

        return $derechos->first(fn (DerechoTenant $derecho): bool => $derecho->ilimitado);
    }

    /**
     * ¿El derecho cubre esta sesion segun sus restricciones de actividad/sucursal?
     * Sin restriccion (nulo) cubre cualquiera.
     */
    private function cubre(DerechoTenant $derecho, SesionTenant $sesion): bool
    {
        $sesion->loadMissing('oferta');

        if ($derecho->actividad_id !== null && (int) $derecho->actividad_id !== (int) $sesion->oferta?->actividad_id) {
            return false;
        }

        if ($derecho->sucursal_id !== null && (int) $derecho->sucursal_id !== (int) $sesion->sucursal_id) {
            return false;
        }

        return true;
    }

    private function vigente(DerechoTenant $derecho, CarbonInterface $momento): bool
    {
        if ($derecho->valido_desde !== null && $momento->lessThan($derecho->valido_desde)) {
            return false;
        }

        if ($derecho->valido_hasta !== null && $momento->greaterThan($derecho->valido_hasta->copy()->endOfDay())) {
            return false;
        }

        return true;
    }
}
