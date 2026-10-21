<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Models\DatosFiscalesPersonaTenant;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\LineaVentaPosTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PresupuestoTenant;
use App\Modules\Tenancy\Models\ReembolsoTenant;
use App\Modules\Tenancy\Models\VentaPosTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\EstadoReembolso;
use App\Modules\Tenancy\Pagos\MetodoPago;
use App\Modules\Tenancy\Pagos\PartesCobro;
use App\Modules\Tenancy\Pasarelas\ConceptoDeCobro;
use App\Modules\Tenancy\Presupuestos\ModoImpuestos;
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
 * @phpstan-type Receptor array{nombre: string|null, email: string|null, rfc: string|null, codigo_postal: string|null, uso_cfdi: string|null, regimen_fiscal: string|null}
 */
class FacturasDeVentasTenant
{
    /** Un servicio (clase, cita, plan): la clave y la unidad que ya sugiere la pantalla. */
    private const SERVICIO = ['clave_prod_serv' => '86121600', 'clave_unidad' => 'E48'];

    /** Un artículo del mostrador: «no existe en el catálogo» y pieza. */
    private const ARTICULO = ['clave_prod_serv' => '01010101', 'clave_unidad' => 'H87'];

    public function __construct(private readonly ParametrosTenant $parametros) {}

    /** La factura timbrada de la orden, si ya la tiene (sus notas de crédito no cuentan). */
    public static function deOrden(int $ordenId): ?FacturaTenant
    {
        return FacturaTenant::query()->where('orden_id', $ordenId)->where('tipo', FacturaTenant::INGRESO)
            ->where('estado', EstadoFactura::Timbrada->value)->latest('id')->first();
    }

    /** La factura timbrada de la venta del mostrador, si ya la tiene. */
    public static function deVenta(int $ventaId): ?FacturaTenant
    {
        return FacturaTenant::query()->where('venta_pos_id', $ventaId)->where('tipo', FacturaTenant::INGRESO)
            ->where('estado', EstadoFactura::Timbrada->value)->latest('id')->first();
    }

    /** Que la orden se pueda facturar: pagada y sin factura timbrada. */
    public function exigirOrden(OrdenTenant $orden): void
    {
        if ($orden->estado !== EstadoOrden::Pagada) {
            throw ValidationException::withMessages(['orden_id' => ['Solo se factura una venta pagada.']]);
        }
        if (self::cobradoNeto($orden) <= 0) {
            throw ValidationException::withMessages(['orden_id' => ['Se devolvió todo lo cobrado: no queda nada que facturar.']]);
        }
        if (self::deOrden((int) $orden->getKey()) !== null) {
            throw ValidationException::withMessages(['orden_id' => ['Esta venta ya está facturada.']]);
        }
    }

    /**
     * Que la venta del mostrador se pueda facturar: vigente, con productos que no se
     * devolvieron y sin factura.
     */
    public function exigirVenta(VentaPosTenant $venta): void
    {
        if ($venta->anulada_en !== null) {
            throw ValidationException::withMessages(['venta_id' => ['Esta venta está anulada.']]);
        }
        if ((int) $venta->total_minor <= 0) {
            throw ValidationException::withMessages(['venta_id' => ['Esta venta no tiene productos que facturar.']]);
        }
        if (! self::quedaPorFacturar($venta)) {
            throw ValidationException::withMessages(['venta_id' => ['Se devolvió todo lo vendido: no queda nada que facturar.']]);
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
        // Lo que de verdad se quedó el negocio: lo cobrado menos lo devuelto.
        $total = min((int) $orden->total_minor, self::cobradoNeto($orden));
        // Un presupuesto con impuestos desglosados trae el impuesto como una línea más
        // (ADR 0127): no es un concepto con IVA encima. Va un solo concepto con el total.
        $desglosado = PresupuestoTenant::query()
            ->where('orden_id', $orden->getKey())
            ->where('impuestos', ModoImpuestos::Desglosados->value)
            ->exists();
        $suman = (int) $lineas->sum('subtotal_minor') === $total;
        $conceptos = $lineas->isNotEmpty() && $suman && ! $desglosado
            ? array_values($lineas->map(fn (LineaOrdenTenant $l): array => $this->concepto(
                $l->nombre(), (int) $l->cantidad, (int) $l->precio_unitario_minor, self::SERVICIO,
            ))->all())
            : [$this->concepto($this->nombreDeOrden($orden, sinImpuestos: $desglosado), 1, $total, self::SERVICIO)];
        $pago = PagoTenant::query()->where('orden_id', $orden->getKey())->whereNotNull('aprobado_en')->latest('id')->first();

        return [
            'etiqueta' => $this->nombreDeOrden($orden),
            'receptor' => $this->receptor($orden->persona),
            'conceptos' => $conceptos,
            'forma_pago' => $pago instanceof PagoTenant ? self::formaDelPago($pago) : '01',
            'total_minor' => $total,
            'moneda' => (string) $orden->moneda,
        ];
    }

    /**
     * Lo cobrado de una orden menos lo devuelto (devoluciones aprobadas): lo que se
     * puede facturar.
     */
    public static function cobradoNeto(OrdenTenant $orden): int
    {
        $pagos = PagoTenant::query()
            ->where('orden_id', $orden->getKey())
            ->whereIn('estado', [EstadoPago::Aprobado->value, EstadoPago::ParcialmenteReembolsado->value, EstadoPago::Reembolsado->value])
            ->get(['id', 'monto_minor']);
        $devuelto = (int) ReembolsoTenant::query()
            ->whereIn('pago_id', $pagos->modelKeys())
            ->where('estado', EstadoReembolso::Aprobado->value)
            ->sum('monto_minor');

        return max(0, (int) $pagos->sum('monto_minor') - $devuelto);
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
        // Lo devuelto no se factura (ADR 0178): por pieza, lo que se cobró por ella, como
        // al devolver.
        $lineas = $venta->lineas->filter(static fn (LineaVentaPosTenant $l): bool => self::piezasQueQuedan($l) > 0);
        $conceptos = array_values($lineas->map(function (LineaVentaPosTenant $l): array {
            $extras = array_map(static fn (array $e): string => (string) $e['nombre'], $l->extras ?? []);
            $nombre = (string) ($l->articulo->nombre ?? 'Producto').($extras !== [] ? ' con '.implode(', ', $extras) : '');

            return $this->concepto($nombre, self::piezasQueQuedan($l), intdiv((int) $l->subtotal_minor, max(1, (int) $l->cantidad)), self::ARTICULO);
        })->all());
        $devuelto = (int) $venta->devoluciones()->sum('monto_minor');

        return [
            'etiqueta' => 'Venta '.TicketVentaPosTenant::folio($venta),
            'receptor' => $this->receptor($venta->persona),
            'conceptos' => $conceptos,
            'forma_pago' => self::formaDeLasPartes(CajasTenant::partesDeVenta($venta)),
            'total_minor' => max(0, (int) $venta->total_minor - $devuelto),
            'moneda' => (string) $venta->moneda,
        ];
    }

    /** ¿Le queda algo sin devolver? Lo que se devolvió completo no se factura. */
    public static function quedaPorFacturar(VentaPosTenant $venta): bool
    {
        return $venta->lineas->contains(static fn (LineaVentaPosTenant $l): bool => self::piezasQueQuedan($l) > 0);
    }

    private static function piezasQueQuedan(LineaVentaPosTenant $linea): int
    {
        return max(0, (int) $linea->cantidad - (int) $linea->cantidad_devuelta);
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
            return ['nombre' => null, 'email' => null, 'rfc' => null, 'codigo_postal' => null, 'uso_cfdi' => null, 'regimen_fiscal' => null];
        }
        $id = (int) $persona->getKey();
        // Los que guardó (él en su cuenta o el negocio en su ficha), antes que los de su
        // última factura.
        $guardados = DatosFiscalesPersonaTenant::query()->where('persona_id', $id)->first();
        if ($guardados instanceof DatosFiscalesPersonaTenant) {
            return $guardados->receptor() + ['uso_cfdi' => $guardados->uso_cfdi];
        }
        $ultima = FacturaTenant::query()
            ->where('estado', EstadoFactura::Timbrada->value)
            ->where('tipo', FacturaTenant::INGRESO)
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
            'regimen_fiscal' => $ultima?->receptor_regimen,
        ];
    }

    /**
     * Lo que lleva la sustituta de una factura (ADR 0180): su receptor, su forma de
     * pago y sus conceptos (sin IVA, como se timbraron), para corregir lo que estaba
     * mal. Una factura de antes de guardar sus conceptos trae los de su venta, si tiene.
     *
     * @return array{etiqueta: string, receptor: Receptor, conceptos: list<Concepto>, forma_pago: string, total_minor: int, moneda: string, anticipo: bool}
     */
    public function borradorDeSustituta(FacturaTenant $factura): array
    {
        /** @var list<Concepto> $conceptos */
        $conceptos = array_values(array_filter(
            (array) ($factura->conceptos ?? []),
            static fn (mixed $c): bool => is_array($c),
        ));
        if ($conceptos === [] && $factura->orden !== null) {
            $conceptos = $this->borradorDeOrden($factura->orden)['conceptos'];
        } elseif ($conceptos === [] && $factura->venta !== null) {
            $conceptos = $this->borradorDeVenta($factura->venta)['conceptos'];
        }

        return [
            // Su folio corto: «Sustituyes la factura A6660546».
            'etiqueta' => mb_strtoupper(mb_substr((string) $factura->uuid, 0, 8)),
            'receptor' => [
                'nombre' => $factura->receptor_nombre,
                'email' => $factura->receptor_email,
                'rfc' => $factura->receptor_rfc,
                'codigo_postal' => $factura->receptor_cp,
                'uso_cfdi' => $factura->uso_cfdi,
                'regimen_fiscal' => $factura->receptor_regimen,
            ],
            'conceptos' => $conceptos,
            'forma_pago' => $factura->forma_pago ?? '01',
            'total_minor' => (int) $factura->total_minor,
            'moneda' => (string) $factura->moneda,
            'anticipo' => (bool) $factura->anticipo,
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

    private function nombreDeOrden(OrdenTenant $orden, bool $sinImpuestos = false): string
    {
        // La línea de impuestos de un presupuesto desglosado no es lo que se vendió.
        $lineas = $sinImpuestos
            ? $orden->lineas->reject(static fn (LineaOrdenTenant $l): bool => $l->getAttribute('producto_comercial_id') === null
                && $l->getAttribute('articulo_id') === null
                && str_starts_with((string) $l->getAttribute('descripcion'), 'Impuestos ('))
            : $orden->lineas;
        $nombres = $lineas->map(static fn (LineaOrdenTenant $l): string => $l->nombre())->filter()->unique()->implode(', ');
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
