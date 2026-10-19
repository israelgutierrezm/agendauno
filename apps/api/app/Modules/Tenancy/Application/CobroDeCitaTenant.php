<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\ArticuloTenant;
use App\Modules\Tenancy\Models\ExtraArticuloTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VentaPosTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cobrar al terminar una cita, en un solo paso: el servicio (su orden, si falta), los
 * productos que se llevó y la propina, con una forma de pago y un comprobante.
 *
 * - Todo o nada: la orden se liquida y la venta se registra en la misma transacción.
 *   Si falta stock o lo recibido no alcanza, no se cobra nada.
 * - El pago del servicio es de su orden (corte, reportes y facturación como siempre);
 *   los productos y la propina son una venta del mostrador ligada a esa orden, con
 *   cuánto fue del servicio, para que el ticket y el cambio sean de todo.
 * - La clave del intento hace idempotente el reintento: devuelve el mismo cobro.
 *
 * @phpstan-type Resumen array{servicio_minor: int, productos_minor: int, propina_minor: int, total_minor: int, recibido_minor: int|null, cambio_minor: int|null, moneda: string, metodo: string, orden: OrdenTenant|null, venta: VentaPosTenant|null}
 */
class CobroDeCitaTenant
{
    public function __construct(
        private readonly OrdenesTenant $ordenes,
        private readonly PuntoDeVentaTenant $pos,
        private readonly RegistrarAuditoria $auditoria,
        private readonly RegionNegocioTenant $region,
    ) {}

    /**
     * @param  list<array{articulo: ArticuloTenant, cantidad: int, extras: list<ExtraArticuloTenant>, precio_minor: int|null}>  $items
     * @return Resumen
     */
    public function cobrar(ReservaTenant $reserva, string $metodo, array $items, int $propinaMinor, ?int $recibidoMinor, ?string $referencia, ?string $clave, ?Usuario $actor): array
    {
        if ($clave !== null) {
            $previa = VentaPosTenant::query()->where('clave_intento', $clave)->first();
            if ($previa instanceof VentaPosTenant) {
                return $this->resumenDeVenta($previa);
            }
        }

        $resumen = DB::connection('tenant')->transaction(function () use ($reserva, $metodo, $items, $propinaMinor, $recibidoMinor, $referencia, $clave, $actor): array {
            $bloqueada = ReservaTenant::query()->whereKey($reserva->getKey())->lockForUpdate()->firstOrFail();
            $sesion = $bloqueada->sesion;
            $sucursal = $sesion !== null ? SucursalTenant::query()->find($sesion->sucursal_id) : null;
            if ($sucursal === null) {
                throw ValidationException::withMessages(['reserva' => ['Esta cita no tiene sucursal: no se puede cobrar aquí.']]);
            }
            $orden = $bloqueada->orden_id !== null
                ? OrdenTenant::query()->whereKey($bloqueada->orden_id)->lockForUpdate()->first()
                : null;
            // Con anticipo ya pagado (ADR 0161), lo que falta es el saldo.
            if ($orden !== null && $orden->estado === EstadoOrden::Pagada && $bloqueada->saldo_orden_id !== null) {
                $orden = OrdenTenant::query()->whereKey($bloqueada->saldo_orden_id)->lockForUpdate()->first() ?? $orden;
            }
            if ($orden !== null && $orden->estado === EstadoOrden::Cancelada) {
                throw ValidationException::withMessages(['reserva' => ['El cobro de esta cita se canceló: no se puede cobrar.']]);
            }
            $servicio = $orden !== null && $orden->estado === EstadoOrden::Pendiente ? $orden->total_minor : 0;
            if ($servicio === 0 && $items === [] && $propinaMinor <= 0) {
                throw ValidationException::withMessages(['reserva' => ['Esta cita ya está cobrada: agrega un producto o una propina para cobrar algo más.']]);
            }

            if ($servicio > 0) {
                $pago = $this->ordenes->liquidar($orden, $metodo, $referencia, $actor);
                if ($pago !== null) {
                    $this->auditoria->registrar($actor, 'pago.registrado', 'pago', (string) $pago->ulid, null, [
                        'orden' => (string) $orden->ulid,
                        'monto_minor' => $pago->monto_minor,
                        'moneda' => $pago->moneda,
                        'metodo' => $metodo,
                        'referencia' => $referencia,
                    ]);
                }
            }

            $venta = null;
            if ($items !== [] || $propinaMinor > 0) {
                // Lo recibido alcanza para todo (la venta lo valida con el servicio).
                $venta = $this->pos->vender(
                    $sucursal, $items, $metodo, $actor, $propinaMinor, $bloqueada->persona,
                    $metodo === 'efectivo' ? $recibidoMinor : null, $clave, $orden, $servicio,
                );
            } elseif ($metodo === 'efectivo' && $recibidoMinor !== null && $recibidoMinor < $servicio) {
                throw ValidationException::withMessages(['recibido_minor' => ['Lo que recibiste no alcanza para cobrar la cita.']]);
            }

            if ($venta !== null) {
                return $this->resumenDeVenta($venta);
            }
            $recibido = $metodo === 'efectivo' ? $recibidoMinor : null;

            return [
                'servicio_minor' => $servicio,
                'productos_minor' => 0,
                'propina_minor' => 0,
                'total_minor' => $servicio,
                'recibido_minor' => $recibido,
                'cambio_minor' => $recibido !== null ? $recibido - $servicio : null,
                'moneda' => $orden->moneda ?? $this->region->moneda(),
                'metodo' => $metodo,
                'orden' => $orden,
                'venta' => null,
            ];
        });

        return $resumen;
    }

    /**
     * @return Resumen
     */
    private function resumenDeVenta(VentaPosTenant $venta): array
    {
        $total = $venta->cobradoMinor();

        return [
            'servicio_minor' => $venta->servicio_minor,
            'productos_minor' => $venta->total_minor,
            'propina_minor' => $venta->propina_minor,
            'total_minor' => $total,
            'recibido_minor' => $venta->recibido_minor,
            'cambio_minor' => $venta->recibido_minor !== null ? $venta->recibido_minor - $total : null,
            'moneda' => (string) $venta->moneda,
            'metodo' => (string) $venta->metodo_pago,
            'orden' => $venta->orden,
            'venta' => $venta,
        ];
    }
}
