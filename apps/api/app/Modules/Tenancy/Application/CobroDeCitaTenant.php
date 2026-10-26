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
use App\Modules\Tenancy\Pagos\PartesCobro;
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
 * - Con varias formas de pago (ADR 0177) las partes suman todo y se reparten en orden:
 *   primero el servicio (su pago), después los productos y la propina (la venta). El
 *   cambio sale solo de la parte en efectivo.
 *
 * @phpstan-type Resumen array{servicio_minor: int, productos_minor: int, propina_minor: int, total_minor: int, recibido_minor: int|null, cambio_minor: int|null, moneda: string, metodo: string, partes: list<array{metodo: string, monto_minor: int}>, orden: OrdenTenant|null, venta: VentaPosTenant|null}
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
     * @param  list<array{metodo: string, monto_minor: int}>|null  $partes
     * @return Resumen
     */
    public function cobrar(ReservaTenant $reserva, string $metodo, array $items, int $propinaMinor, ?int $recibidoMinor, ?string $referencia, ?string $clave, ?Usuario $actor, ?string $huella = null, ?array $partes = null, ?string $cupon = null): array
    {
        $huella ??= self::huella($reserva, $metodo, $items, $propinaMinor, $recibidoMinor, $referencia, $partes, $cupon);
        if ($clave !== null) {
            $previa = VentaPosTenant::query()->where('clave_intento', $clave)->first();
            if ($previa instanceof VentaPosTenant) {
                PuntoDeVentaTenant::exigirMismaOperacion($previa, (int) $reserva->getKey(), $huella);

                return $this->resumenDeVenta($previa);
            }
        }

        $resumen = DB::connection('tenant')->transaction(function () use ($reserva, $metodo, $items, $propinaMinor, $recibidoMinor, $referencia, $clave, $actor, $huella, $partes, $cupon): array {
            $bloqueada = ReservaTenant::query()->whereKey($reserva->getKey())->lockForUpdate()->firstOrFail();
            // Ya con la cita bajo candado: si el mismo intento llegó dos veces a la vez
            // (doble clic, reintento de red), el segundo encuentra aquí el cobro del
            // primero y lo devuelve, en lugar de responder que ya está cobrada (R04).
            $previa = $clave !== null ? VentaPosTenant::query()->where('clave_intento', $clave)->first() : null;
            if ($previa instanceof VentaPosTenant) {
                PuntoDeVentaTenant::exigirMismaOperacion($previa, (int) $reserva->getKey(), $huella);

                return $this->resumenDeVenta($previa);
            }
            $sesion = $bloqueada->sesion;
            $sucursal = $sesion !== null ? SucursalTenant::query()->find($sesion->sucursal_id) : null;
            if ($sucursal === null) {
                throw ValidationException::withMessages(['reserva' => ['Esta cita no tiene sucursal: no se puede cobrar aquí.']]);
            }
            // Su orden (o la del anticipo, ADR 0161) y la del resto, bloqueadas por id.
            $ordenes = OrdenTenant::query()
                ->whereKey(array_values(array_filter([$bloqueada->orden_id, $bloqueada->saldo_orden_id])))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $principal = $bloqueada->orden_id !== null ? $ordenes->get($bloqueada->orden_id) : null;
            $resto = $bloqueada->saldo_orden_id !== null ? $ordenes->get($bloqueada->saldo_orden_id) : null;
            if ($principal?->estado === EstadoOrden::Cancelada || ($principal?->estado === EstadoOrden::Pagada && $resto?->estado === EstadoOrden::Cancelada)) {
                throw ValidationException::withMessages(['reserva' => ['El cobro de esta cita se canceló: no se puede cobrar.']]);
            }
            // Lo que falta: el anticipo sin pagar y el resto se cobran juntos.
            $aCobrar = collect([$principal, $resto])
                ->filter(static fn (?OrdenTenant $o): bool => $o?->estado === EstadoOrden::Pendiente)
                ->values();
            // Un cupón (ADR 0220): a lo último que falta (el resto, si hay anticipo).
            if ($cupon !== null && $cupon !== '') {
                $ultima = $aCobrar->last();
                if (! $ultima instanceof OrdenTenant) {
                    throw ValidationException::withMessages(['cupon' => ['Esta cita ya está cobrada: el cupón no se aplica.']]);
                }
                $conCupon = $this->ordenes->aplicarCupon($ultima, $cupon);
                $aCobrar = $aCobrar->map(static fn (OrdenTenant $o): OrdenTenant => $o->is($conCupon) ? $conCupon : $o);
            }
            $orden = $aCobrar->first() ?? $resto ?? $principal;
            $servicio = (int) $aCobrar->sum('total_minor');
            if ($servicio === 0 && $items === [] && $propinaMinor <= 0) {
                throw ValidationException::withMessages(['reserva' => ['Esta cita ya está cobrada: agrega un producto o una propina para cobrar algo más.']]);
            }

            // Con varias formas: suman todo y se reparten en orden (servicio, productos y
            // propina); cada lado guarda lo suyo (ADR 0177).
            $metodoServicio = $metodo;
            $metodoVenta = $metodo;
            $partesServicio = null;
            $partesVenta = null;
            $enEfectivo = null;
            if ($partes !== null) {
                $productos = array_sum(array_map(static fn (array $i): int => ((int) $i['articulo']->precio_minor
                    + array_sum(array_map(static fn (ExtraArticuloTenant $e): int => (int) $e->precio_minor, $i['extras']))) * max(1, $i['cantidad']), $items));
                PartesCobro::exigirTotal($partes, $servicio + $productos + $propinaMinor);
                [$delServicio, $deProductos, $dePropina] = PartesCobro::repartir($partes, [$servicio, $productos, $propinaMinor]);
                $deVenta = PartesCobro::juntar([...$deProductos, ...$dePropina]);
                $metodoServicio = $delServicio === [] ? $metodo : PartesCobro::metodo($delServicio);
                $partesServicio = count($delServicio) > 1 ? $delServicio : null;
                $metodoVenta = $deVenta === [] ? PartesCobro::MIXTO : PartesCobro::metodo($deVenta);
                $partesVenta = count($deVenta) > 1 ? $deVenta : null;
                $enEfectivo = PartesCobro::efectivo($partes);
            }

            if ($servicio > 0) {
                // Con anticipo y resto, cada orden con lo suyo de las formas (en orden).
                $porOrden = $partesServicio !== null
                    ? PartesCobro::repartir($partesServicio, $aCobrar->map(static fn (OrdenTenant $o): int => (int) $o->total_minor)->all())
                    : null;
                foreach ($aCobrar as $i => $porCobrar) {
                    $suyas = $porOrden[$i] ?? null;
                    $metodoDeEsta = $suyas !== null && $suyas !== [] ? PartesCobro::metodo($suyas) : $metodoServicio;
                    $partesDeEsta = $suyas !== null && count($suyas) > 1 ? $suyas : null;
                    // A la caja de la sede de la cita (ADR 0175).
                    $pago = $this->ordenes->liquidar($porCobrar, $metodoDeEsta, $referencia, $actor, (int) $sucursal->getKey(), $partesDeEsta);
                    if ($pago !== null) {
                        $this->auditoria->registrar($actor, 'pago.registrado', 'pago', (string) $pago->ulid, null, [
                            'orden' => (string) $porCobrar->ulid,
                            'monto_minor' => $pago->monto_minor,
                            'moneda' => $pago->moneda,
                            'metodo' => $partesDeEsta !== null ? PartesCobro::MIXTO : $metodoDeEsta,
                            'partes' => $partesDeEsta,
                            'referencia' => $referencia,
                        ]);
                    }
                }
            }

            // Siempre su venta (aun solo el servicio): lo recibido alcanza para lo que se
            // paga en efectivo (la venta lo valida con el servicio) y queda el comprobante.
            $conEfectivo = $partes !== null ? $enEfectivo > 0 : $metodo === 'efectivo';
            $venta = $this->pos->vender(
                $sucursal, $items, $metodoVenta, $actor, $propinaMinor, $bloqueada->persona,
                $conEfectivo ? $recibidoMinor : null, $clave, $orden, $servicio,
                (int) $bloqueada->getKey(), $huella, $partesVenta, $enEfectivo,
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
     * @param  list<array{metodo: string, monto_minor: int}>|null  $partes
     */
    public static function huella(ReservaTenant $reserva, string $metodo, array $items, int $propinaMinor, ?int $recibidoMinor, ?string $referencia, ?array $partes = null, ?string $cupon = null): string
    {
        $pedido = [
            'reserva' => (string) $reserva->ulid,
            'metodo' => $metodo,
            'items' => array_map(static fn (array $i): array => [
                (string) $i['articulo']->ulid, $i['cantidad'],
                array_map(static fn (ExtraArticuloTenant $e): string => (string) $e->ulid, $i['extras']),
                $i['precio_minor'],
            ], $items),
            'propina' => $propinaMinor,
            'recibido' => $metodo === 'efectivo' || ($partes !== null && PartesCobro::efectivo($partes) > 0) ? $recibidoMinor : null,
            'referencia' => $referencia,
        ];
        // Sin partes, la huella de siempre (los cobros ya hechos se siguen reconociendo).
        if ($partes !== null) {
            $pedido['partes'] = $partes;
        }
        // Con cupón, también (sin él, la de siempre).
        if ($cupon !== null && $cupon !== '') {
            $pedido['cupon'] = mb_strtoupper(trim($cupon));
        }

        return PuntoDeVentaTenant::huella('cita', $pedido);
    }

    /**
     * @return Resumen
     */
    private function resumenDeVenta(VentaPosTenant $venta): array
    {
        $total = $venta->cobradoMinor();
        // Todas las formas del cobro (el servicio y lo del mostrador): el cambio sale
        // solo del efectivo (ADR 0177).
        $partes = CajasTenant::partesDelCobro($venta);
        $mixto = count($partes) > 1;

        return [
            'servicio_minor' => $venta->servicio_minor,
            'productos_minor' => $venta->total_minor,
            'propina_minor' => $venta->propina_minor,
            'total_minor' => $total,
            'recibido_minor' => $venta->recibido_minor,
            'cambio_minor' => $venta->recibido_minor !== null ? $venta->recibido_minor - ($mixto ? PartesCobro::efectivo($partes) : $total) : null,
            'moneda' => (string) $venta->moneda,
            'metodo' => $mixto ? PartesCobro::MIXTO : (string) $venta->metodo_pago,
            'partes' => $mixto ? $partes : [],
            'orden' => $venta->orden,
            'venta' => $venta,
        ];
    }
}
