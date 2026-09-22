<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\EstadoFactura;
use App\Modules\Tenancy\Exceptions\CargoRentaNoFacturable;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\TimbradoFallido;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\FacturaPlataforma;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Emite (timbra) el CFDI de un cargo de renta del SaaS: AgendaUno es el emisor (llave
 * FacturAPI de plataforma) y el estudio el receptor. Reusa {@see ClienteFacturacion}.
 * A diferencia del CFDI tenant (precio + IVA por encima), aquí el `monto_minor` del
 * cargo es el TOTAL ya cobrado (IVA incluido): se desglosa hacia atrás para que el CFDI
 * cuadre exactamente con lo pagado. Idempotente: un CFDI por cargo (unique
 * cargo_renta_id); si ya está timbrado, lo devuelve sin volver a timbrar.
 */
class EmitirFacturaPlataforma
{
    /** IVA estándar (16%): factor 1.16 = 29/25 para desglose entero exacto. */
    private const IVA_NUM = 25;

    private const IVA_DEN = 29;

    public function __construct(private readonly ClienteFacturacion $cliente) {}

    /**
     * @param  array{nombre: string, rfc: string, email?: string|null, codigo_postal: string, regimen_fiscal?: string|null}  $receptor
     */
    public function emitir(CargoRenta $cargo, array $receptor): FacturaPlataforma
    {
        if ($cargo->estado !== EstadoCargoRenta::Pagado) {
            throw new CargoRentaNoFacturable('Solo se factura un cargo pagado.');
        }

        $previa = FacturaPlataforma::query()->where('cargo_renta_id', $cargo->getKey())->first();
        if ($previa instanceof FacturaPlataforma && $previa->estado === EstadoFactura::Timbrada) {
            return $previa;
        }

        // El monto del cargo es el TOTAL (IVA incluido); se desglosa hacia atrás.
        $total = $cargo->monto_minor;
        $subtotal = intdiv($total * self::IVA_NUM, self::IVA_DEN);
        $impuesto = $total - $subtotal;

        $llave = (string) (ConfiguracionPlataforma::llaveFacturapi() ?? '');
        $cuerpo = $this->armarCuerpo($cargo, $receptor);

        return DB::transaction(function () use ($cargo, $receptor, $subtotal, $impuesto, $total, $llave, $cuerpo): FacturaPlataforma {
            $comun = [
                'estudio_id' => $cargo->estudio_id,
                'receptor_nombre' => $receptor['nombre'],
                'receptor_rfc' => mb_strtoupper($receptor['rfc']),
                'receptor_email' => $receptor['email'] ?? null,
                'receptor_regimen' => $receptor['regimen_fiscal'] ?? null,
                'receptor_cp' => $receptor['codigo_postal'],
                'uso_cfdi' => (string) config('turnouno.facturapi.renta.uso_cfdi'),
                'moneda' => $cargo->moneda,
                'subtotal_minor' => $subtotal,
                'impuesto_minor' => $impuesto,
                'total_minor' => $total,
            ];

            try {
                $resultado = $this->cliente->timbrar($llave, $cuerpo);
            } catch (TimbradoFallido $e) {
                return FacturaPlataforma::query()->updateOrCreate(
                    ['cargo_renta_id' => $cargo->getKey()],
                    $comun + [
                        'estado' => EstadoFactura::Error->value,
                        'motivo_error' => $e->getMessage(),
                        'facturapi_id' => null,
                        'uuid' => null,
                        'timbrada_en' => null,
                    ],
                );
            }

            return FacturaPlataforma::query()->updateOrCreate(
                ['cargo_renta_id' => $cargo->getKey()],
                $comun + [
                    'estado' => EstadoFactura::Timbrada->value,
                    'facturapi_id' => $resultado->facturaId,
                    'uuid' => $resultado->uuid,
                    'motivo_error' => null,
                    'timbrada_en' => Carbon::now(),
                ],
            );
        });
    }

    /**
     * @param  array{nombre: string, rfc: string, email?: string|null, codigo_postal: string, regimen_fiscal?: string|null}  $receptor
     * @return array<string, mixed>
     */
    private function armarCuerpo(CargoRenta $cargo, array $receptor): array
    {
        return [
            'customer' => [
                'legal_name' => $receptor['nombre'],
                'tax_id' => mb_strtoupper($receptor['rfc']),
                'tax_system' => $receptor['regimen_fiscal'] ?? null,
                'email' => $receptor['email'] ?? null,
                'address' => ['zip' => $receptor['codigo_postal']],
            ],
            'items' => [[
                'quantity' => 1,
                'product' => [
                    'description' => "Suscripción AgendaUno {$cargo->periodo}",
                    'product_key' => (string) config('turnouno.facturapi.renta.clave_prod_serv'),
                    'unit_key' => (string) config('turnouno.facturapi.renta.clave_unidad'),
                    // El precio es IVA incluido: FacturAPI extrae el impuesto para cuadrar con lo cobrado.
                    'price' => $cargo->monto_minor / 100,
                    'tax_included' => true,
                    'taxes' => [['type' => 'IVA', 'rate' => 0.16]],
                ],
            ]],
            'use' => (string) config('turnouno.facturapi.renta.uso_cfdi'),
            'payment_form' => (string) config('turnouno.facturapi.renta.forma_pago'),
            'currency' => $cargo->moneda,
        ];
    }
}
