<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;

/**
 * El pago con que se cobró una orden, para corregir su forma de pago o anularlo desde
 * donde se ve la venta (la agenda, Ventas, la ficha; ADR 0223): `{id, metodo, en_caja,
 * corregible, anulable}` o null si la orden no está pagada. Las reglas son las de
 * {@see CorregirMetodoPagoTenant} y {@see AnularCobroTenant}.
 */
class PagoDeOrdenTenant
{
    public function __construct(
        private readonly CorregirMetodoPagoTenant $corregirMetodo,
        private readonly AnularCobroTenant $anularCobro,
    ) {}

    /**
     * @return array{id: string, metodo: string|null, en_caja: bool, corregible: bool, anulable: bool}|null
     */
    public function presentar(?OrdenTenant $orden): ?array
    {
        if ($orden === null || $orden->estado !== EstadoOrden::Pagada) {
            return null;
        }
        $pago = $orden->pagos->first(fn (PagoTenant $p): bool => $p->estado !== EstadoPago::Pendiente && $p->estado !== EstadoPago::Rechazado);
        if (! $pago instanceof PagoTenant) {
            return null;
        }

        return [
            'id' => (string) $pago->ulid,
            'metodo' => $orden->metodo_pago ?? $pago->metodo?->value,
            'en_caja' => $pago->proveedor === 'manual',
            'corregible' => $this->corregirMetodo->impedimento($pago, conFactura: false) === null,
            'anulable' => $this->anularCobro->impedimento($pago->setRelation('orden', $orden), completo: false) === null,
        ];
    }
}
