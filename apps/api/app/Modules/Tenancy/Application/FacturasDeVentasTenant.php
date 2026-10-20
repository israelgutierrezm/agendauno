<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\LineaVentaPosTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\VentaPosTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Modules\Tenancy\Pagos\PartesCobro;
use App\Modules\Tenancy\Pasarelas\ConceptoDeCobro;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * La factura de una venta (ADR 0179): una orden pagada (un plan, una cita, una compra)
 * o una venta del mostrador. Cada venta se factura una sola vez; así el candado de «ya
 * facturada» funciona al anular o corregir, y quien ve una sede ve sus facturas.
 *
 * El borrador arma lo que se factura. Los precios de venta ya traen el IVA (se le
 * cobra al cliente el precio publicado), así que cada concepto va sin IVA y el CFDI se
 * lo suma: el total puede diferir por centavos de redondeo. La propina del mostrador no
 * se factura (no es ingreso del negocio, ADR 0139).
 *
 * A quién: la persona de la venta y, si ya se le facturó antes, con los datos fiscales
 * de su última factura (RFC, código postal y uso del CFDI), para no volver a pedirlos.
 *
 * @phpstan-type Concepto array{descripcion: string, cantidad: int, precio_unitario_minor: int, clave_prod_serv: string, clave_unidad: string}
 * @phpstan-type Receptor array{nombre: string|null, email: string|null, rfc: string|null, codigo_postal: string|null, uso_cfdi: string|null}
 */
class FacturasDeVentasTenant
{
    /** Un servicio (clase, cita, plan): la clave y la unidad que ya sugiere la pantalla. */
    private const SERVICIO = ['clave_prod_serv' => '86121600', 'clave_unidad' => 'E48'];

    /** Un artículo del mostrador: «no existe en el catálogo» y pieza. */
    private const ARTICULO = ['clave_prod_serv' => '01010101', 'clave_unidad' => 'H87'];

    public function __construct(private readonly ParametrosTenant $parametros) {}

    /** La factura timbrada de la orden, si ya la tiene. */
    public static function deOrden(int $ordenId): ?FacturaTenant
    {
        return FacturaTenant::query()->where('orden_id', $ordenId)->where('estado', EstadoFactura::Timbrada->value)->latest('id')->first();
    }

    /** La factura timbrada de la venta del mostrador, si ya la tiene. */
    public static function deVenta(int $ventaId): ?FacturaTenant
    {
        return FacturaTenant::query()->where('venta_pos_id', $ventaId)->where('estado', EstadoFactura::Timbrada->value)->latest('id')->first();
    }

    /** Que la orden se pueda facturar: pagada y sin factura timbrada. */
    public function exigirOrden(OrdenTenant $orden): void
    {
        if ($orden->estado !== EstadoOrden::Pagada) {
            throw ValidationException::withMessages(['orden_id' => ['Solo se factura una venta pagada.']]);
        }
        if (self::deOrden((int) $orden->getKey()) !== null) {
            throw ValidationException::withMessages(['orden_id' => ['Esta venta ya está facturada.']]);
        }
    }

    /** Que la venta del mostrador se pueda facturar: vigente, con productos y sin factura. */
    public function exigirVenta(VentaPosTenant $venta): void
    {
        if ($venta->anulada_en !== null) {
            throw ValidationException::withMessages(['venta_id' => ['Esta venta está anulada.']]);
        }
        if ((int) $venta->total_minor <= 0) {
            throw ValidationException::withMessages(['venta_id' => ['Esta venta no tiene productos que facturar.']]);
        }
        if (self::deVenta((int) $venta->getKey()) !== null) {
            throw ValidationException::withMessages(['venta_id' => ['Esta venta ya está facturada.']]);
        }
    }

    /**
     * Lo que se factura de una orden: sus líneas (si suman lo pagado) o un concepto con
     * el total, a quién y con qué forma de pago del SAT.
     *
     * @return array{etiqueta: string, receptor: Receptor, conceptos: list<Concepto>, forma_pago: string, total_minor: int, moneda: string}
     */
    public function borradorDeOrden(OrdenTenant $orden): array
    {
        $orden->loadMissing(['lineas.producto', 'persona', 'sesion.oferta']);
        $lineas = $orden->lineas;
        $suman = (int) $lineas->sum('subtotal_minor') === (int) $orden->total_minor;
        $conceptos = $lineas->isNotEmpty() && $suman
            ? array_values($lineas->map(fn (LineaOrdenTenant $l): array => $this->concepto(
                $l->nombre(), (int) $l->cantidad, (int) $l->precio_unitario_minor, self::SERVICIO,
            ))->all())
            : [$this->concepto($this->nombreDeOrden($orden), 1, (int) $orden->total_minor, self::SERVICIO)];
        $pago = PagoTenant::query()->where('orden_id', $orden->getKey())->whereNotNull('aprobado_en')->latest('id')->first();

        return [
            'etiqueta' => $this->nombreDeOrden($orden),
            'receptor' => $this->receptor($orden->persona),
            'conceptos' => $conceptos,
            'forma_pago' => $pago instanceof PagoTenant ? self::formaDelPago($pago) : '01',
            'total_minor' => (int) $orden->total_minor,
            'moneda' => (string) $orden->moneda,
        ];
    }

    /**
     * Lo que se factura de una venta del mostrador: sus artículos (con extras), sin la
     * propina.
     *
     * @return array{etiqueta: string, receptor: Receptor, conceptos: list<Concepto>, forma_pago: string, total_minor: int, moneda: string}
     */
    public function borradorDeVenta(VentaPosTenant $venta): array
    {
        $venta->loadMissing(['lineas.articulo', 'persona']);
        $conceptos = array_values($venta->lineas->map(function (LineaVentaPosTenant $l): array {
            $extras = array_map(static fn (array $e): string => (string) $e['nombre'], $l->extras ?? []);
            $nombre = (string) ($l->articulo->nombre ?? 'Producto').($extras !== [] ? ' con '.implode(', ', $extras) : '');

            return $this->concepto($nombre, (int) $l->cantidad, intdiv((int) $l->subtotal_minor, max(1, (int) $l->cantidad)), self::ARTICULO);
        })->all());

        return [
            'etiqueta' => 'Venta '.TicketVentaPosTenant::folio($venta),
            'receptor' => $this->receptor($venta->persona),
            'conceptos' => $conceptos,
            'forma_pago' => self::formaDeLasPartes(CajasTenant::partesDeVenta($venta)),
            'total_minor' => (int) $venta->total_minor,
            'moneda' => (string) $venta->moneda,
        ];
    }

    /**
     * A quién se factura: la persona y, si ya se le facturó, los datos fiscales de su
     * última factura (el nombre fiscal puede no ser el de la persona).
     *
     * @return Receptor
     */
    private function receptor(?PersonaTenant $persona): array
    {
        if (! $persona instanceof PersonaTenant) {
            return ['nombre' => null, 'email' => null, 'rfc' => null, 'codigo_postal' => null, 'uso_cfdi' => null];
        }
        $id = (int) $persona->getKey();
        $ultima = FacturaTenant::query()
            ->where('estado', EstadoFactura::Timbrada->value)
            ->where('anticipo', false)
            ->where(fn (Builder $q) => $q
                ->whereIn('orden_id', OrdenTenant::query()->select('id')->where('persona_id', $id))
                ->orWhereIn('venta_pos_id', VentaPosTenant::query()->select('id')->where('persona_id', $id)))
            ->latest('id')
            ->first();

        return [
            'nombre' => $ultima->receptor_nombre ?? $persona->nombreCompleto(),
            'email' => $ultima->receptor_email ?? $persona->email,
            'rfc' => $ultima?->receptor_rfc,
            'codigo_postal' => $ultima?->receptor_cp,
            'uso_cfdi' => $ultima?->uso_cfdi,
        ];
    }

    /**
     * Un concepto con su precio sin IVA (el de venta ya lo trae).
     *
     * @param  array{clave_prod_serv: string, clave_unidad: string}  $claves
     * @return Concepto
     */
    private function concepto(string $descripcion, int $cantidad, int $precioConIva, array $claves): array
    {
        $iva = $this->parametros->entero('facturacion.iva_porcentaje');

        return [
            'descripcion' => mb_substr($descripcion, 0, 1000),
            'cantidad' => max(1, $cantidad),
            'precio_unitario_minor' => max(1, (int) round($precioConIva * 100 / (100 + $iva))),
            ...$claves,
        ];
    }

    private function nombreDeOrden(OrdenTenant $orden): string
    {
        $nombres = $orden->lineas->map(static fn (LineaOrdenTenant $l): string => $l->nombre())->filter()->unique()->implode(', ');
        if ($nombres !== '') {
            return mb_substr($nombres, 0, 255);
        }
        $oferta = $orden->sesion?->oferta?->nombre;

        return $oferta !== null ? (ConceptoDeCobro::deLaSesion($orden) ?? 'Servicio').': '.$oferta : 'Compra';
    }

    /**
     * La forma de pago del SAT de un pago: efectivo y depósitos en efectivo 01,
     * transferencia 03 y tarjeta 04 (el negocio la cambia si fue de débito, 28). Uno
     * mixto, la forma con la que se pagó más (ADR 0177).
     */
    public static function formaDelPago(PagoTenant $pago): string
    {
        return match ($pago->metodo) {
            MetodoPago::Efectivo, MetodoPago::Ventanilla, MetodoPago::Oxxo => '01',
            MetodoPago::Spei => '03',
            MetodoPago::Mixto => self::formaDeLasPartes(CajasTenant::partesDelPago($pago)),
            default => $pago->proveedor === 'efectivo' ? '01' : '04',
        };
    }

    /**
     * La forma del SAT de lo cobrado en caja: la de la parte más grande.
     *
     * @param  list<array{metodo: string, monto_minor: int}>  $partes
     */
    private static function formaDeLasPartes(array $partes): string
    {
        $mayor = collect(PartesCobro::juntar($partes))->sortByDesc('monto_minor')->first();

        return match ($mayor['metodo'] ?? 'efectivo') {
            'efectivo', 'ventanilla' => '01',
            'transferencia' => '03',
            default => '04',
        };
    }
}
