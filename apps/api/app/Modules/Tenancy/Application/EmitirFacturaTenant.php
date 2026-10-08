<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\TimbradoFallido;
use App\Modules\Tenancy\Models\DatosFiscalesTenant;
use App\Modules\Tenancy\Models\FacturaTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Emite (timbra) un CFDI tenant-local vía {@see ClienteFacturacion}. Calcula el
 * desglose (subtotal + IVA) en minor (entero), arma el cuerpo del CFDI con el emisor
 * del tenant y el receptor dado, y guarda la {@see FacturaTenant} con el resultado
 * (Timbrada con UUID, o Error con el motivo). Emite `factura.timbrada` al outbox.
 *
 * Gasta un timbre del negocio por factura timbrada (ADR 0107): sin timbres no se
 * timbra, y si el proveedor la rechaza no se gasta.
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
     */
    public function emitir(
        DatosFiscalesTenant $emisor,
        array $receptor,
        array $items,
        string $usoCfdi,
        string $formaPago = '01',
        string $moneda = 'MXN',
    ): FacturaTenant {
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
            $receptor, $subtotal, $impuesto, $total, $moneda, $usoCfdi, $llave, $cuerpo
        ): FacturaTenant {
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
