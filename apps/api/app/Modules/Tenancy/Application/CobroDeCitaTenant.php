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
 * - Todo cobro deja su venta, aunque sea solo el servicio (R04/R06): es la operación
 *   que se recupera y el comprobante que se manda. Sus sumas no cuentan el servicio
 *   dos veces (va aparte, en `servicio_minor`).
 * - La clave del intento hace idempotente el reintento: lo mismo devuelve el mismo
 *   cobro; la clave con otra cita u otros importes responde 409 (R05).
 *
 * @phpstan-type Resumen array{servicio_minor: int, productos_minor: int, propina_minor: int, total_minor: int, recibido_minor: int|null, cambio_minor: int|null, moneda: string, metodo: string, orden: OrdenTenant|null, venta: VentaPosTenant|null}
 */
class CobroDeCitaTenant
{
    public function __construct(
        private readonly OrdenesTenant $ordenes,
        private readonly PuntoDeVentaTenant $pos,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /**
     * @param  list<array{articulo: ArticuloTenant, cantidad: int, extras: list<ExtraArticuloTenant>, precio_minor: int|null}>  $items
     * @return Resumen
     */
    public function cobrar(ReservaTenant $reserva, string $metodo, array $items, int $propinaMinor, ?int $recibidoMinor, ?string $referencia, ?string $clave, ?Usuario $actor, ?string $huella = null): array
    {
        $huella ??= self::huella($reserva, $metodo, $items, $propinaMinor, $recibidoMinor, $referencia);
        if ($clave !== null) {
            $previa = VentaPosTenant::query()->where('clave_intento', $clave)->first();
            if ($previa instanceof VentaPosTenant) {
                PuntoDeVentaTenant::exigirMismaOperacion($previa, (int) $reserva->getKey(), $huella);

                return $this->resumenDeVenta($previa);
            }
        }

        $resumen = DB::connection('tenant')->transaction(function () use ($reserva, $metodo, $items, $propinaMinor, $recibidoMinor, $referencia, $clave, $actor, $huella): array {
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

            // Siempre su venta (aun solo el servicio): lo recibido alcanza para todo (la
            // venta lo valida con el servicio) y queda el comprobante.
            $venta = $this->pos->vender(
                $sucursal, $items, $metodo, $actor, $propinaMinor, $bloqueada->persona,
                $metodo === 'efectivo' ? $recibidoMinor : null, $clave, $orden, $servicio,
                (int) $bloqueada->getKey(), $huella,
            );

            return $this->resumenDeVenta($venta);
        });

        return $resumen;
    }

    /**
     * La huella de lo pedido (R05): la cita, la forma de pago, los productos (con sus
     * extras y el precio mostrado), la propina, lo recibido y la referencia.
     *
     * @param  list<array{articulo: ArticuloTenant, cantidad: int, extras: list<ExtraArticuloTenant>, precio_minor: int|null}>  $items
     */
    public static function huella(ReservaTenant $reserva, string $metodo, array $items, int $propinaMinor, ?int $recibidoMinor, ?string $referencia): string
    {
        return PuntoDeVentaTenant::huella('cita', [
            'reserva' => (string) $reserva->ulid,
            'metodo' => $metodo,
            'items' => array_map(static fn (array $i): array => [
                (string) $i['articulo']->ulid, $i['cantidad'],
                array_map(static fn (ExtraArticuloTenant $e): string => (string) $e->ulid, $i['extras']),
                $i['precio_minor'],
            ], $items),
            'propina' => $propinaMinor,
            'recibido' => $metodo === 'efectivo' ? $recibidoMinor : null,
            'referencia' => $referencia,
        ]);
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
