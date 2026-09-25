<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;

/**
 * La deuda del periodo de una membresía: su orden de renovación pendiente. Se
 * reutiliza en cada intento de cobro (no se abre una por intento) y, al pagarse por
 * cualquier camino, el fulfillment avanza la fecha de renovación.
 */
class DeudaDeRenovacionTenant
{
    public function __construct(private readonly OrdenesTenant $ordenes) {}

    /**
     * La orden de renovación pendiente del acuerdo, o una nueva con el precio
     * vigente del producto. Null si la membresía ya no tiene titular o producto.
     */
    public function de(AcuerdoTenant $acuerdo): ?OrdenTenant
    {
        $acuerdo->loadMissing(['persona', 'producto']);
        $persona = $acuerdo->persona;
        $producto = $acuerdo->producto;
        if (! $persona instanceof PersonaTenant || ! $producto instanceof ProductoTenant) {
            return null;
        }

        $pendiente = OrdenTenant::query()
            ->where('renueva_acuerdo_id', $acuerdo->getKey())
            ->where('estado', EstadoOrden::Pendiente->value)
            ->latest('id')
            ->first();
        if ($pendiente instanceof OrdenTenant) {
            return $pendiente;
        }

        $orden = $this->ordenes->crear($persona, [['producto' => $producto, 'cantidad' => 1, 'beneficiario' => null]]);
        $orden->update(['renueva_acuerdo_id' => $acuerdo->getKey()]);

        return $orden;
    }

    /**
     * Las membresías ya no se renuevan (se cancelaron): su orden de renovación
     * pendiente, si la había (p. ej. la abrió el aviso de renovación), deja de estar
     * por cobrar.
     *
     * @param  array<int, mixed>  $acuerdoIds
     */
    public function anular(array $acuerdoIds, ?Usuario $actor = null): void
    {
        if ($acuerdoIds === []) {
            return;
        }

        OrdenTenant::query()
            ->whereIn('renueva_acuerdo_id', $acuerdoIds)
            ->where('estado', EstadoOrden::Pendiente->value)
            ->update([
                'estado' => EstadoOrden::Cancelada->value,
                'cancelada_en' => now(),
                'cancelada_por' => $actor?->getKey(),
            ]);
    }
}
