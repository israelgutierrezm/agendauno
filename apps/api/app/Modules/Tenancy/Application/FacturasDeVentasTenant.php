<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Facturacion\FotoFiscal;
use App\Modules\Tenancy\Facturacion\TratamientoIva;
use App\Modules\Tenancy\Models\DatosFiscalesPersonaTenant;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Models\LineaDevolucionVentaTenant;
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
 * El IVA y las claves del SAT (ADR 0228): los de la foto fiscal que cada línea guardó al
 * venderse ({@see FotoFiscal}), no los del catálogo de hoy. Cada línea es su concepto,
 * así nunca se juntan en uno conceptos exentos y con IVA. Lo que no se cobró de la lista
 * (el descuento de la orden) y lo devuelto se reparten entre las líneas en proporción a
 * lo que valen; la promoción no toca las cuotas. Una venta de antes de guardar la foto
 * se factura con el catálogo de hoy, y el borrador lo dice (`fiscal_del_catalogo`).
 *
 * @phpstan-type Concepto array{descripcion: string, cantidad: int, precio_unitario_minor: int, clave_prod_serv: string, clave_unidad: string, iva: string}
 * @phpstan-type Receptor array{nombre: string|null, email: string|null, rfc: string|null, codigo_postal: string|null, uso_cfdi: string|null, regimen_fiscal: string|null}
 * @phpstan-type Borrador array{etiqueta: string, receptor: Receptor, conceptos: list<Concepto>, forma_pago: string, total_minor: int, moneda: string, descuento_minor: int, devuelto_minor: int, fiscal_del_catalogo: bool}
 */
class FacturasDeVentasTenant
{
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
     * Lo que se factura de una orden: cada línea con su foto fiscal y lo que de verdad se
     * cobró de ella (el descuento y lo devuelto, repartidos), a quién y con qué forma de
     * pago del SAT. La de una cita (sin líneas), un concepto con la foto de su servicio.
     *
     * @return Borrador
     */
    public function borradorDeOrden(OrdenTenant $orden): array
    {
        $orden->loadMissing(['lineas.producto', 'persona', 'sesion.oferta']);
        // Lo que de verdad se quedó el negocio: lo cobrado menos lo devuelto.
        $total = min((int) $orden->total_minor, self::cobradoNeto($orden));
        // Un presupuesto con impuestos desglosados trae el impuesto como una línea más
        // (ADR 0127): no es un producto con IVA encima. Su importe va en lo vendido.
        $desglosado = PresupuestoTenant::query()
            ->where('orden_id', $orden->getKey())
            ->where('impuestos', ModoImpuestos::Desglosados->value)
            ->exists();
        $lineas = array_values(($desglosado ? $orden->lineas->reject(self::esLineaDeImpuestos(...)) : $orden->lineas)->all());
        $conceptos = [];
        $delCatalogo = false;
        if ($lineas === []) {
            $delCatalogo = ! $orden->tieneFotoFiscal();
            if ($total > 0) {
                $conceptos[] = $this->concepto($this->nombreDeOrden($orden), 1, $total, $orden->fotoFiscal());
            }
        } else {
            foreach (self::cobradoDeCadaLinea($orden, $lineas, $total) as $i => $importe) {
                if ($importe <= 0) {
                    continue;
                }
                $linea = $lineas[$i];
                $delCatalogo = $delCatalogo || ! $linea->tieneFotoFiscal();
                $conceptos[] = $this->conceptoPorPiezas($linea->nombre(), (int) $linea->cantidad, $importe, $linea->fotoFiscal());
            }
        }
        $pago = PagoTenant::query()->where('orden_id', $orden->getKey())->whereNotNull('aprobado_en')->latest('id')->first();

        return [
            'etiqueta' => $this->nombreDeOrden($orden),
            'receptor' => $this->receptor($orden->persona),
            'conceptos' => $conceptos,
            'forma_pago' => $pago instanceof PagoTenant ? self::formaDelPago($pago) : '01',
            'total_minor' => $total,
            'moneda' => (string) $orden->moneda,
            // Lo que no se factura, a la vista (ADR 0228): el descuento de la orden y lo devuelto.
            'descuento_minor' => (int) $orden->descuento_minor,
            'devuelto_minor' => self::devueltoDe($orden),
            'fiscal_del_catalogo' => $delCatalogo,
        ];
    }

    /**
     * Lo que de verdad se cobró de cada línea (con IVA): su subtotal menos su parte de la
     * promoción (que no toca las cuotas, B04) y, en proporción a lo que queda, ajustado
     * a lo que se factura (el descuento de contado, lo devuelto o los impuestos
     * desglosados de un presupuesto). Suma exactamente `$total`.
     *
     * @param  list<LineaOrdenTenant>  $lineas
     * @return list<int>
     */
    private static function cobradoDeCadaLinea(OrdenTenant $orden, array $lineas, int $total): array
    {
        $importes = array_map(static fn (LineaOrdenTenant $l): int => max(0, (int) $l->subtotal_minor), $lineas);
        $promocion = max(0, (int) $orden->descuento_minor - (int) $orden->descuento_contado_minor);
        $vendido = array_map(static fn (LineaOrdenTenant $l, int $importe): int => $l->concepto === null ? $importe : 0, $lineas, $importes);
        if ($promocion > 0 && array_sum($vendido) > 0) {
            foreach (self::repartir($vendido, min($promocion, array_sum($vendido))) as $i => $parte) {
                $importes[$i] -= $parte;
            }
        }

        return self::repartir($importes, $total);
    }

    /**
     * Reparte `$monto` en proporción a los pesos, en centavos exactos: lo que sobra del
     * redondeo va a los de mayor fracción. Sin pesos, todo al último.
     *
     * @param  list<int>  $pesos
     * @return list<int>
     */
    public static function repartir(array $pesos, int $monto): array
    {
        $suma = array_sum($pesos);
        if ($pesos === [] || $suma <= 0) {
            return $pesos === [] ? [] : [...array_fill(0, count($pesos) - 1, 0), $monto];
        }
        $partes = [];
        $fracciones = [];
        foreach ($pesos as $i => $peso) {
            $partes[$i] = intdiv($monto * $peso, $suma);
            $fracciones[$i] = ($monto * $peso) % $suma;
        }
        $falta = $monto - array_sum($partes);
        arsort($fracciones);
        foreach (array_keys($fracciones) as $i) {
            if ($falta <= 0) {
                break;
            }
            $partes[$i]++;
            $falta--;
        }

        return array_values($partes);
    }

    /** La línea «Impuestos (…)» de un presupuesto desglosado (ADR 0127): no es un producto. */
    private static function esLineaDeImpuestos(LineaOrdenTenant $linea): bool
    {
        return $linea->getAttribute('producto_comercial_id') === null
            && $linea->getAttribute('articulo_id') === null
            && str_starts_with((string) $linea->getAttribute('descripcion'), 'Impuestos (');
    }

    /**
     * La factura de una venta lleva el IVA de su foto (ADR 0228), no el que diga la
     * pantalla, o 422 en `items`. Si todo lo vendido lleva el mismo IVA, cada concepto va
     * con ese (quien no lo dice, lo toma). Si mezcla, cada concepto dice el suyo y lo de
     * cada IVA es lo de la venta, a unos centavos. En una venta de antes de guardar la
     * foto, el catálogo de hoy es solo una sugerencia: vale lo que se revisó en pantalla.
     *
     * @template T of array{descripcion: string, cantidad: int, precio_unitario_minor: int, clave_prod_serv: string, clave_unidad: string, iva?: string|null}
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public function conIvaDeLaVenta(array $items, OrdenTenant|VentaPosTenant $venta): array
    {
        $borrador = $venta instanceof OrdenTenant ? $this->borradorDeOrden($venta) : $this->borradorDeVenta($venta);
        $deLaVenta = self::importesPorIva($borrador['conceptos']);
        $conFoto = ! $borrador['fiscal_del_catalogo'];
        if (count($deLaVenta) <= 1) {
            $iva = (string) (array_key_first($deLaVenta) ?? TratamientoIva::General->value);
            $items = array_map(static fn (array $i): array => ($i['iva'] ?? '') === '' ? array_merge($i, ['iva' => $iva]) : $i, $items);
            $cuadra = ! $conFoto || array_diff(array_column($items, 'iva'), [$iva]) === [];
        } else {
            $cuadra = array_filter($items, static fn (array $i): bool => ($i['iva'] ?? '') === '') === [];
            if ($conFoto) {
                $deLaFactura = self::importesPorIva($items);
                $cuadra = $cuadra && array_keys($deLaFactura) === array_keys($deLaVenta);
                foreach ($deLaVenta as $iva => $importe) {
                    $cuadra = $cuadra && abs(($deLaFactura[$iva] ?? 0) - $importe) <= count($items) + 1;
                }
            }
        }
        if (! $cuadra) {
            throw ValidationException::withMessages(['items' => [
                'Cada concepto de esta venta va con el IVA con que se vendió y, si mezcla exentos y con IVA, con su importe. Vuelve a cargar la venta.',
            ]]);
        }

        return $items;
    }

    /**
     * Lo de cada IVA (sin IVA), ordenado por IVA.
     *
     * @param  array<int, array{cantidad: int, precio_unitario_minor: int, iva?: string|null}>  $conceptos
     * @return array<string, int>
     */
    private static function importesPorIva(array $conceptos): array
    {
        $por = [];
        foreach ($conceptos as $c) {
            $iva = TratamientoIva::de($c['iva'] ?? null)->value;
            $por[$iva] = ($por[$iva] ?? 0) + (int) $c['cantidad'] * (int) $c['precio_unitario_minor'];
        }
        ksort($por);

        return $por;
    }

    /**
     * Lo cobrado de una orden menos lo devuelto (devoluciones aprobadas): lo que se
     * puede facturar.
     */
    public static function cobradoNeto(OrdenTenant $orden): int
    {
        return max(0, (int) self::pagosCobrados($orden)->sum('monto_minor') - self::devueltoDe($orden));
    }

    /** Lo devuelto de lo cobrado de una orden (devoluciones aprobadas). */
    private static function devueltoDe(OrdenTenant $orden): int
    {
        return (int) ReembolsoTenant::query()
            ->whereIn('pago_id', self::pagosCobrados($orden)->select('id'))
            ->where('estado', EstadoReembolso::Aprobado->value)
            ->sum('monto_minor');
    }

    /**
     * @return Builder<PagoTenant>
     */
    private static function pagosCobrados(OrdenTenant $orden): Builder
    {
        return PagoTenant::query()
            ->where('orden_id', $orden->getKey())
            ->whereIn('estado', [EstadoPago::Aprobado->value, EstadoPago::ParcialmenteReembolsado->value, EstadoPago::Reembolsado->value]);
    }

    /**
     * Lo que se factura de una venta del mostrador: sus artículos (con extras) con la
     * foto fiscal de cada uno, sin la propina. El cupón ya viene repartido en las líneas
     * (ADR 0220); lo devuelto no se factura (ADR 0178): de cada línea, lo que se cobró
     * menos lo que se le devolvió.
     *
     * @return Borrador
     */
    public function borradorDeVenta(VentaPosTenant $venta): array
    {
        $venta->loadMissing(['lineas.articulo', 'persona']);
        $devueltoDe = LineaDevolucionVentaTenant::query()
            ->whereIn('linea_venta_pos_id', $venta->lineas->modelKeys())
            ->selectRaw('linea_venta_pos_id, SUM(monto_minor) as monto')
            ->groupBy('linea_venta_pos_id')
            ->pluck('monto', 'linea_venta_pos_id');
        $conceptos = [];
        $delCatalogo = false;
        foreach ($venta->lineas as $l) {
            $piezas = self::piezasQueQuedan($l);
            $importe = max(0, (int) $l->subtotal_minor - (int) ($devueltoDe[$l->getKey()] ?? 0));
            if ($piezas <= 0 || $importe <= 0) {
                continue;
            }
            $extras = array_map(static fn (array $e): string => (string) $e['nombre'], $l->extras ?? []);
            $nombre = (string) ($l->articulo->nombre ?? 'Producto').($extras !== [] ? ' con '.implode(', ', $extras) : '');
            $delCatalogo = $delCatalogo || ! $l->tieneFotoFiscal();
            $conceptos[] = $this->conceptoPorPiezas($nombre, $piezas, $importe, $l->fotoFiscal());
        }
        $devuelto = (int) $venta->devoluciones()->sum('monto_minor');

        return [
            'etiqueta' => 'Venta '.TicketVentaPosTenant::folio($venta),
            'receptor' => $this->receptor($venta->persona),
            'conceptos' => $conceptos,
            'forma_pago' => self::formaDeLasPartes(CajasTenant::partesDeVenta($venta)),
            'total_minor' => max(0, (int) $venta->total_minor - $devuelto),
            'moneda' => (string) $venta->moneda,
            'descuento_minor' => (int) $venta->descuento_minor,
            'devuelto_minor' => $devuelto,
            'fiscal_del_catalogo' => $delCatalogo,
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
     * Un concepto con su precio sin IVA (el de venta ya lo trae; exento o a tasa cero,
     * no lleva), con el IVA y las claves del SAT de su foto fiscal.
     *
     * @return Concepto
     */
    private function concepto(string $descripcion, int $cantidad, int $precioConIva, FotoFiscal $foto): array
    {
        return [
            'descripcion' => mb_substr($descripcion, 0, 1000),
            'cantidad' => max(1, $cantidad),
            'precio_unitario_minor' => max(1, $foto->iva->sinIva($precioConIva, $this->parametros->entero('facturacion.iva_porcentaje') * 100)),
            'clave_prod_serv' => $foto->claveProdServ,
            'clave_unidad' => $foto->claveUnidad,
            'iva' => $foto->iva->value,
        ];
    }

    /**
     * El concepto de una línea por lo que se cobró de ella (con IVA): con sus piezas si
     * el importe se divide exacto entre ellas; si no (un descuento repartido), una sola
     * partida que dice cuántas fueron, para no perder centavos.
     *
     * @return Concepto
     */
    private function conceptoPorPiezas(string $nombre, int $piezas, int $importe, FotoFiscal $foto): array
    {
        if ($piezas > 1 && $importe % $piezas !== 0) {
            return $this->concepto("{$piezas} × {$nombre}", 1, $importe, $foto);
        }

        return $this->concepto($nombre, max(1, $piezas), intdiv($importe, max(1, $piezas)), $foto);
    }

    private function nombreDeOrden(OrdenTenant $orden): string
    {
        // La línea de impuestos de un presupuesto desglosado no es lo que se vendió.
        $nombres = $orden->lineas->reject(self::esLineaDeImpuestos(...))->map(static fn (LineaOrdenTenant $l): string => $l->nombre())->filter()->unique()->implode(', ');
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
