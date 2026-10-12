<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ProductoTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use Illuminate\Support\Facades\DB;

/**
 * La deuda del periodo de una membresía: su orden de renovación pendiente. Se
 * reutiliza en cada intento de cobro (no se abre una por intento) y, al pagarse por
 * cualquier camino, el fulfillment avanza la fecha de renovación.
 */
class DeudaDeRenovacionTenant
{
    public function __construct(
        private readonly OrdenesTenant $ordenes,
        private readonly DomiciliacionesTenant $domiciliaciones,
    ) {}

    /**
     * La orden de renovación pendiente del acuerdo, o una nueva: con el precio que el
     * cliente autorizó en su pago automático, si lo tiene (su precio contratado, ADR
     * 0134), o con el vigente del producto. Null si la membresía ya no tiene titular o
     * producto.
     */
    public function de(AcuerdoTenant $acuerdo): ?OrdenTenant
    {
        $acuerdo->loadMissing(['persona', 'producto']);
        $persona = $acuerdo->persona;
        $producto = $acuerdo->producto;
        if (! $persona instanceof PersonaTenant || ! $producto instanceof ProductoTenant) {
            return null;
        }

        // Con la membresía bloqueada: dos cobros a la vez (el proceso diario y un
        // reintento) no abren dos deudas por el mismo periodo (M07).
        return DB::connection('tenant')->transaction(function () use ($acuerdo, $persona, $producto): OrdenTenant {
            AcuerdoTenant::query()->whereKey($acuerdo->getKey())->lockForUpdate()->first();

            $pendiente = OrdenTenant::query()
                ->where('renueva_acuerdo_id', $acuerdo->getKey())
                ->where('estado', EstadoOrden::Pendiente->value)
                ->latest('id')
                ->first();
            if ($pendiente instanceof OrdenTenant) {
                return $pendiente;
            }

            // Renovación de lo que ya tiene: aunque el producto se haya archivado.
            $item = ['producto' => $producto, 'cantidad' => 1, 'beneficiario' => null];
            $contratado = $this->domiciliaciones->precioContratado($acuerdo);
            if ($contratado !== null) {
                $item['precio_minor'] = $contratado['monto_minor'];
            }
            $orden = $this->ordenes->crear($persona, [$item], renovacion: true);
            $orden->update(['renueva_acuerdo_id' => $acuerdo->getKey()]);

            return $orden;
        });
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
