<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Facturacion\CatalogosSat;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\EstadoCancelacion;
use App\Modules\Tenancy\Facturacion\TimbradoFallido;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\FacturaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\VentaPosTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Emite (timbra) un CFDI tenant-local vía {@see ClienteFacturacion}. Calcula el
 * desglose (subtotal + IVA) en minor (entero), arma el cuerpo del CFDI con el emisor
 * del tenant y el receptor dado, y guarda la {@see FacturaTenant} con el resultado
 * (Timbrada con UUID, o Error con el motivo). Emite `factura.timbrada` al outbox.
 *
 * Gasta un timbre del negocio por factura timbrada (ADR 0107): sin timbres no se
 * timbra, y si el proveedor la rechaza no se gasta.
 *
 * Anticipos (ADR 0163, CFDI «por remanente»): una factura puede ser de un anticipo
 * (un solo concepto con la clave del SAT) y la del resto se relaciona con los
 * anticipos que aplica (tipo 07) y lleva la leyenda del remanente. Cada anticipo se
 * aplica una sola vez: se bloquea y se revalida en la misma transacción.
 *
 * La venta que factura (ADR 0179), si se dice: una orden o una venta del mostrador.
 * Se bloquea y se revalida en la misma transacción, así dos timbrados a la vez no la
 * facturan dos veces.
 *
 * Sustituir y notas de crédito (ADR 0180): la sustituta se relaciona con la que
 * sustituye (`04`) y toma su venta; la nota de crédito (egreso) se relaciona con la
 * factura que corrige (`01`) y no pasa de lo que queda por acreditar. Se guardan el
 * régimen del receptor, la forma de pago y los conceptos con que se timbró.
 */
class EmitirFacturaTenant
{
    public function __construct(
        private readonly ClienteFacturacion $cliente,
        private readonly RegistrarEventoTenant $eventos,
        // Tasa de IVA (16, u 8 en la región fronteriza): la fija el negocio (ADR 0047).
        private readonly ParametrosTenant $parametros,
        // Cada factura timbrada gasta un timbre del negocio (ADR 0107).
        private readonly TimbresTenant $timbres,
    ) {}

    /**
     * @param  array{nombre: string, rfc: string, email?: string|null, codigo_postal: string, regimen_fiscal?: string|null}  $receptor
     * @param  list<array{descripcion: string, cantidad: int, precio_unitario_minor: int, clave_prod_serv: string, clave_unidad: string}>  $items
     * @param  list<string>  $anticipos  ULID de las facturas de anticipo que aplica
     * @param  int|null  $ordenId  la orden que factura
     * @param  int|null  $ventaPosId  la venta del mostrador que factura
     * @param  FacturaTenant|null  $sustituye  la factura que sustituye (su cancelación se pide aparte)
     */
    public function emitir(
        DatosFiscalesTenant $emisor,
        array $receptor,
        array $items,
        string $usoCfdi,
        string $formaPago = '01',
        string $moneda = 'MXN',
        bool $anticipo = false,
        array $anticipos = [],
        ?int $ordenId = null,
        ?int $ventaPosId = null,
        ?FacturaTenant $sustituye = null,
    ): FacturaTenant {
        if ($anticipo) {
            $items = [['cantidad' => 1, 'precio_unitario_minor' => (int) $items[0]['precio_unitario_minor']] + CatalogosSat::ANTICIPO];
        } elseif ($anticipos !== []) {
            $items = array_map(static fn (array $i): array => [
                ...$i,
                'descripcion' => mb_substr($i['descripcion'].' - '.CatalogosSat::LEYENDA_REMANENTE, 0, 1000),
            ], $items);
        }
        $subtotal = array_sum(array_map(
            static fn (array $i): int => $i['cantidad'] * $i['precio_unitario_minor'],
            $items,
        ));
        $ivaBps = $this->parametros->entero('facturacion.iva_porcentaje') * 100;
        $impuesto = self::ivaDe($items, $ivaBps);
        $total = $subtotal + $impuesto;

        $llave = (string) ($emisor->facturapi_llave ?? config('agendauno.facturapi.llave') ?? '');
        $cuerpo = $this->armarCuerpo($receptor, $items, $usoCfdi, $formaPago, $moneda, $ivaBps);

        return DB::connection('tenant')->transaction(function () use (
            $receptor, $subtotal, $impuesto, $total, $moneda, $usoCfdi, $formaPago, $items, $llave, $cuerpo, $anticipo, $anticipos, $ordenId, $ventaPosId, $sustituye
        ): FacturaTenant {
            $relacionados = [];
            // La que sustituye, bloqueada y revisada: la nueva toma su venta.
            if ($sustituye instanceof FacturaTenant) {
                $sustituye = $this->exigirSustituible($sustituye, $anticipo, $anticipos);
                if (($ordenId !== null && $ordenId !== $sustituye->orden_id) || ($ventaPosId !== null && $ventaPosId !== $sustituye->venta_pos_id)) {
                    throw ValidationException::withMessages(['sustituye' => ['La sustituta es de la misma venta que la factura que sustituye.']]);
                }
                $ordenId = $sustituye->orden_id !== null ? (int) $sustituye->orden_id : null;
                $ventaPosId = $sustituye->venta_pos_id !== null ? (int) $sustituye->venta_pos_id : null;
                $relacionados[] = ['relationship' => FacturaTenant::RELACION_SUSTITUCION, 'documents' => [(string) $sustituye->uuid]];
            }
            $suya = static fn (?FacturaTenant $f): bool => $f !== null && $f->getKey() !== $sustituye?->getKey();
            // La venta que factura, bloqueada: si otro timbrado ya la facturó, no otra vez.
            if ($ordenId !== null) {
                $orden = OrdenTenant::query()->whereKey($ordenId)->lockForUpdate()->first();
                if ($suya(FacturasDeVentasTenant::deOrden($ordenId))) {
                    throw ValidationException::withMessages(['orden_id' => ['Esta venta ya está facturada.']]);
                }
                // Con lo devuelto mientras tanto, no se timbra de más.
                if ($orden instanceof OrdenTenant && FacturasDeVentasTenant::cobradoNeto($orden) <= 0) {
                    throw ValidationException::withMessages(['orden_id' => ['Se devolvió todo lo cobrado: no queda nada que facturar.']]);
                }
            }
            if ($ventaPosId !== null) {
                $venta = VentaPosTenant::query()->whereKey($ventaPosId)->lockForUpdate()->first();
                if ($suya(FacturasDeVentasTenant::deVenta($ventaPosId))) {
                    throw ValidationException::withMessages(['venta_id' => ['Esta venta ya está facturada.']]);
                }
                // Anulada o devuelta mientras tanto: ya no se timbra.
                if ($venta instanceof VentaPosTenant && $venta->anulada_en !== null) {
                    throw ValidationException::withMessages(['venta_id' => ['Esta venta está anulada.']]);
                }
                if ($venta instanceof VentaPosTenant && ! FacturasDeVentasTenant::quedaPorFacturar($venta)) {
                    throw ValidationException::withMessages(['venta_id' => ['Se devolvió todo lo vendido: no queda nada que facturar.']]);
                }
            }
            // Los anticipos que aplica: suyos (mismo RFC), timbrados y aún sin aplicar.
            $aplicados = FacturaTenant::query()->whereIn('ulid', $anticipos)->lockForUpdate()->get();
            if ($aplicados->count() !== count(array_unique($anticipos))
                || $aplicados->contains(fn (FacturaTenant $f): bool => ! $f->anticipo
                    || $f->estado !== EstadoFactura::Timbrada
                    || $f->anticipo_aplicado_en !== null
                    || $f->receptor_rfc !== mb_strtoupper((string) $receptor['rfc']))) {
                throw ValidationException::withMessages(['anticipos' => ['Algún anticipo ya se aplicó, no está timbrado o es de otro RFC.']]);
            }
            if ($aplicados->isNotEmpty()) {
                $relacionados[] = [
                    'relationship' => CatalogosSat::RELACION_ANTICIPO,
                    'documents' => $aplicados->pluck('uuid')->values()->all(),
                ];
            }
            if ($relacionados !== []) {
                $cuerpo['related_documents'] = $relacionados;
            }
            $comun = [
                'receptor_nombre' => $receptor['nombre'],
                'receptor_rfc' => mb_strtoupper($receptor['rfc']),
                'receptor_email' => $receptor['email'] ?? null,
                'uso_cfdi' => $usoCfdi,
                'receptor_cp' => $receptor['codigo_postal'],
                'moneda' => $moneda,
                'subtotal_minor' => $subtotal,
                'impuesto_minor' => $impuesto,
                'total_minor' => $total,
                'anticipo' => $anticipo,
                'orden_id' => $ordenId,
                'venta_pos_id' => $ventaPosId,
                'tipo' => FacturaTenant::INGRESO,
                'relacionada_id' => $sustituye?->getKey(),
                'relacion' => $sustituye instanceof FacturaTenant ? FacturaTenant::RELACION_SUSTITUCION : null,
                'receptor_regimen' => $receptor['regimen_fiscal'] ?? null,
                'forma_pago' => $formaPago,
                'conceptos' => $items,
            ];

            $factura = $this->timbrarYGuardar($llave, $cuerpo, $comun);
            if ($factura->estado === EstadoFactura::Timbrada) {
                FacturaTenant::query()->whereKey($aplicados->modelKeys())->update(['anticipo_aplicado_en' => $factura->getKey()]);
            }

            return $factura;
        });
    }

    /**
     * Una nota de crédito (CFDI de egreso) por `$montoMinor` (con IVA) de una factura
     * timbrada: una devolución, un descuento o una bonificación. Al mismo receptor, con
     * la forma en que se le devolvió el dinero.
     */
    public function emitirNotaDeCredito(
        DatosFiscalesTenant $emisor,
        FacturaTenant $original,
        int $montoMinor,
        string $descripcion,
        string $formaPago,
        ?string $regimenReceptor,
    ): FacturaTenant {
        $ivaBps = $this->parametros->entero('facturacion.iva_porcentaje') * 100;
        // El importe sin IVA más grande cuyo total (con el IVA redondeado, como el CFDI)
        // no pasa del monto: así una nota por el total acredita el total.
        $subtotal = intdiv($montoMinor * 10000, 10000 + $ivaBps) + 1;
        while ($subtotal > 1 && $subtotal + self::ivaDeImporte($subtotal, $ivaBps) > $montoMinor) {
            $subtotal--;
        }
        $impuesto = self::ivaDeImporte($subtotal, $ivaBps);
        $total = $subtotal + $impuesto;
        $items = [['descripcion' => mb_substr($descripcion, 0, 1000), 'cantidad' => 1, 'precio_unitario_minor' => $subtotal] + CatalogosSat::NOTA_DE_CREDITO];
        $llave = (string) ($emisor->facturapi_llave ?? config('agendauno.facturapi.llave') ?? '');

        return DB::connection('tenant')->transaction(function () use ($original, $items, $subtotal, $impuesto, $total, $formaPago, $regimenReceptor, $llave, $ivaBps): FacturaTenant {
            $original = FacturaTenant::query()->whereKey($original->getKey())->lockForUpdate()->firstOrFail();
            if ($original->estado !== EstadoFactura::Timbrada || $original->tipo !== FacturaTenant::INGRESO) {
                throw ValidationException::withMessages(['factura' => ['Solo se acredita una factura de venta timbrada.']]);
            }
            if ($original->anticipo) {
                throw ValidationException::withMessages(['factura' => ['Un anticipo se aplica al facturar el resto, no con nota de crédito.']]);
            }
            if ($original->cancelacion_estado === EstadoCancelacion::Pendiente) {
                throw ValidationException::withMessages(['factura' => ['Su cancelación está en espera: no se le hacen notas de crédito.']]);
            }
            $regimen = $original->receptor_regimen ?? $regimenReceptor;
            if ($regimen === null || $regimen === '') {
                throw ValidationException::withMessages(['regimen_fiscal' => ['Falta el régimen fiscal del cliente: va en la nota de crédito.']]);
            }
            $queda = $original->acreditable();
            if ($total > $queda) {
                throw ValidationException::withMessages(['monto_minor' => ['Pasa de lo que queda por acreditar de esta factura.']]);
            }

            $receptor = [
                'nombre' => $original->receptor_nombre,
                'rfc' => $original->receptor_rfc,
                'email' => $original->receptor_email,
                'codigo_postal' => $original->receptor_cp,
                'regimen_fiscal' => $regimen,
            ];
            $cuerpo = ['type' => FacturaTenant::EGRESO]
                + $this->armarCuerpo($receptor, $items, CatalogosSat::USO_NOTA_DE_CREDITO, $formaPago, (string) $original->moneda, $ivaBps)
                + ['related_documents' => [['relationship' => FacturaTenant::RELACION_NOTA_DE_CREDITO, 'documents' => [(string) $original->uuid]]]];

            $nota = $this->timbrarYGuardar($llave, $cuerpo, [
                'receptor_nombre' => $original->receptor_nombre,
                'receptor_rfc' => $original->receptor_rfc,
                'receptor_email' => $original->receptor_email,
                'uso_cfdi' => CatalogosSat::USO_NOTA_DE_CREDITO,
                'receptor_cp' => $original->receptor_cp,
                'moneda' => $original->moneda,
                'subtotal_minor' => $subtotal,
                'impuesto_minor' => $impuesto,
                'total_minor' => $total,
                'anticipo' => false,
                // La venta de la factura que corrige: para ver por sede (no la factura).
                'orden_id' => $original->orden_id,
                'venta_pos_id' => $original->venta_pos_id,
                'tipo' => FacturaTenant::EGRESO,
                'relacionada_id' => $original->getKey(),
                'relacion' => FacturaTenant::RELACION_NOTA_DE_CREDITO,
                'receptor_regimen' => $regimen,
                'forma_pago' => $formaPago,
                'conceptos' => $items,
            ]);
            // El régimen del cliente queda en la factura: la siguiente nota no lo pide.
            if ($nota->estado === EstadoFactura::Timbrada && $original->receptor_regimen === null) {
                $original->forceFill(['receptor_regimen' => $regimen])->save();
            }

            return $nota;
        });
    }

    /**
     * El IVA como lo calcula el CFDI: el de cada concepto, redondeado al centavo, y su
     * suma (no el del subtotal truncado, que podía diferir por un centavo).
     *
     * @param  list<array{cantidad: int, precio_unitario_minor: int}>  $items
     */
    private static function ivaDe(array $items, int $ivaBps): int
    {
        return array_sum(array_map(
            static fn (array $i): int => self::ivaDeImporte($i['cantidad'] * $i['precio_unitario_minor'], $ivaBps),
            $items,
        ));
    }

    /** El IVA de un importe, redondeado al centavo (la mitad hacia arriba). */
    private static function ivaDeImporte(int $importe, int $ivaBps): int
    {
        return intdiv($importe * $ivaBps + 5000, 10000);
    }

    /**
     * La factura que se sustituye, bloqueada: timbrada, de una venta, sin cancelación
     * en espera, sin notas de crédito ni anticipos aplicados (el SAT no la cancelaría
     * con documentos relacionados vigentes), y del mismo tipo (anticipo o no).
     *
     * @param  list<string>  $anticipos
     */
    private function exigirSustituible(FacturaTenant $factura, bool $anticipo, array $anticipos): FacturaTenant
    {
        $factura = FacturaTenant::query()->whereKey($factura->getKey())->lockForUpdate()->firstOrFail();
        $motivo = match (true) {
            $factura->estado !== EstadoFactura::Timbrada || $factura->tipo !== FacturaTenant::INGRESO => 'Solo se sustituye una factura de venta timbrada.',
            $factura->cancelacion_estado === EstadoCancelacion::Pendiente => 'Su cancelación ya está en espera.',
            $factura->notasDeCredito()->exists() => 'Tiene notas de crédito: cancélalas antes de sustituirla.',
            FacturaTenant::query()->where('anticipo_aplicado_en', $factura->getKey())->exists() || $anticipos !== [] => 'Aplica anticipos: cancélala y factura de nuevo el resto.',
            $factura->anticipo && $factura->anticipo_aplicado_en !== null => 'Este anticipo ya se aplicó: no se sustituye.',
            $factura->anticipo !== $anticipo => $factura->anticipo ? 'La sustituta de un anticipo es otro anticipo.' : 'La sustituta de una venta no es un anticipo.',
            default => null,
        };
        if ($motivo !== null) {
            throw ValidationException::withMessages(['sustituye' => [$motivo]]);
        }

        return $factura;
    }

    /**
     * Timbra y guarda (dentro de la transacción): con un timbre del negocio, timbrada
     * con su UUID o con el error del proveedor (y sin gastar el timbre).
     *
     * @param  array<string, mixed>  $cuerpo
     * @param  array<string, mixed>  $comun
     */
    private function timbrarYGuardar(string $llave, array $cuerpo, array $comun): FacturaTenant
    {
        $saldo = $this->timbres->apartarParaTimbrar();
        // La llave de idempotencia de FacturAPI: la huella de lo que se pide y cuántas
        // facturas iguales ya se timbraron. Si FacturAPI no respondió a tiempo (y esta
        // transacción se deshizo), el reintento lleva la misma llave y recibe la misma
        // factura; una igual después de cancelar la anterior es otra llave.
        // SHA-256 recortado a la columna (40): sha1 no se usa (preset de seguridad).
        $huella = substr(hash('sha256', (string) json_encode($cuerpo)), 0, 40);
        $previas = FacturaTenant::query()->where('huella', $huella)->whereNotNull('facturapi_id')->count();
        $comun['huella'] = $huella;
        try {
            $resultado = $this->cliente->timbrar($llave, $cuerpo + ['idempotency_key' => $huella.'-'.$previas]);
        } catch (TimbradoFallido $e) {
            return FacturaTenant::query()->create($comun + [
                'estado' => EstadoFactura::Error->value,
                'motivo_error' => $e->getMessage(),
            ]);
        }
        // Ya registrada (dos solicitudes iguales a la vez recibieron la misma): esa.
        $registrada = FacturaTenant::query()->where('facturapi_id', $resultado->facturaId)->first();
        if ($registrada instanceof FacturaTenant) {
            return $registrada;
        }

        $base = rtrim((string) config('agendauno.facturapi.base_url'), '/');
        $factura = FacturaTenant::query()->create($comun + [
            'estado' => EstadoFactura::Timbrada->value,
            'facturapi_id' => $resultado->facturaId,
            'uuid' => $resultado->uuid,
            'pdf_url' => "{$base}/invoices/{$resultado->facturaId}/pdf",
            'xml_url' => "{$base}/invoices/{$resultado->facturaId}/xml",
            'timbrada_en' => Carbon::now(),
        ]);
        $this->timbres->gastar($saldo, (string) $factura->ulid);

        $this->eventos->registrar('factura.timbrada', 'factura', $factura->ulid, [
            'factura' => $factura->ulid,
            'uuid' => $factura->uuid,
            'total_minor' => $factura->total_minor,
            'tipo' => $factura->tipo,
        ]);

        return $factura;
    }

    /**
     * @param  array<string, mixed>  $receptor
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function armarCuerpo(array $receptor, array $items, string $usoCfdi, string $formaPago, string $moneda, int $ivaBps): array
    {
        return [
            'customer' => [
                'legal_name' => $receptor['nombre'],
                'tax_id' => mb_strtoupper((string) $receptor['rfc']),
                'tax_system' => $receptor['regimen_fiscal'] ?? null,
                'email' => $receptor['email'] ?? null,
                'address' => ['zip' => $receptor['codigo_postal']],
            ],
            'items' => array_map(static fn (array $i): array => [
                'quantity' => $i['cantidad'],
                'product' => [
                    'description' => $i['descripcion'],
                    'product_key' => $i['clave_prod_serv'],
                    'unit_key' => $i['clave_unidad'],
                    'price' => $i['precio_unitario_minor'] / 100,
                    'tax_included' => false,
                    'taxes' => [['type' => 'IVA', 'rate' => $ivaBps / 10000]],
                ],
            ], $items),
            'use' => $usoCfdi,
            'payment_form' => $formaPago,
            'currency' => $moneda,
        ];
    }
}
