<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Facturacion\CatalogosSat;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
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
        $impuesto = intdiv($subtotal * $ivaBps, 10000);
        $total = $subtotal + $impuesto;

        $llave = (string) ($emisor->facturapi_llave ?? config('agendauno.facturapi.llave') ?? '');
        $cuerpo = $this->armarCuerpo($receptor, $items, $usoCfdi, $formaPago, $moneda, $ivaBps);

        return DB::connection('tenant')->transaction(function () use (
            $receptor, $subtotal, $impuesto, $total, $moneda, $usoCfdi, $llave, $cuerpo, $anticipo, $anticipos, $ordenId, $ventaPosId
        ): FacturaTenant {
            // La venta que factura, bloqueada: si otro timbrado ya la facturó, no otra vez.
            if ($ordenId !== null) {
                OrdenTenant::query()->whereKey($ordenId)->lockForUpdate()->first();
                if (FacturasDeVentasTenant::deOrden($ordenId) !== null) {
                    throw ValidationException::withMessages(['orden_id' => ['Esta venta ya está facturada.']]);
                }
            }
            if ($ventaPosId !== null) {
                VentaPosTenant::query()->whereKey($ventaPosId)->lockForUpdate()->first();
                if (FacturasDeVentasTenant::deVenta($ventaPosId) !== null) {
                    throw ValidationException::withMessages(['venta_id' => ['Esta venta ya está facturada.']]);
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
                $cuerpo['related_documents'] = [[
                    'relationship' => CatalogosSat::RELACION_ANTICIPO,
                    'documents' => $aplicados->pluck('uuid')->values()->all(),
                ]];
            }
            $saldo = $this->timbres->apartarParaTimbrar();
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
            ];

            try {
                $resultado = $this->cliente->timbrar($llave, $cuerpo);
            } catch (TimbradoFallido $e) {
                return FacturaTenant::query()->create($comun + [
                    'estado' => EstadoFactura::Error->value,
                    'motivo_error' => $e->getMessage(),
                ]);
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
            FacturaTenant::query()->whereKey($aplicados->modelKeys())->update(['anticipo_aplicado_en' => $factura->getKey()]);

            $this->eventos->registrar('factura.timbrada', 'factura', $factura->ulid, [
                'factura' => $factura->ulid,
                'uuid' => $factura->uuid,
                'total_minor' => $factura->total_minor,
            ]);

            return $factura;
        });
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
