<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Facturacion;

use Illuminate\Support\Str;

/**
 * Proveedor de facturación FALSO (dev/test y modo no-configurado): simula un timbre
 * determinista sin llamar a FacturAPI. Permite ejercitar todo el pipeline de
 * facturación sin llaves reales.
 */
class FacturacionFalsa implements CancelacionCfdi, ClienteFacturacion
{
    public function timbrar(string $llaveOrganizacion, array $factura): ResultadoTimbre
    {
        return new ResultadoTimbre('fake_'.Str::lower((string) Str::ulid()), (string) Str::uuid());
    }

    public function descargar(string $llaveOrganizacion, string $facturaId, string $formato): string
    {
        return $formato === 'xml'
            ? '<?xml version="1.0"?><cfdi:Comprobante fake="'.$facturaId.'"/>'
            : '%PDF-1.4 fake '.$facturaId;
    }

    /** El SAT la acepta en el acto (sin cliente que deba aceptarla). */
    public function cancelar(string $llaveOrganizacion, string $facturaId, string $motivo, ?string $sustituta = null): EstadoCancelacion
    {
        return EstadoCancelacion::Aceptada;
    }

    public function consultarCancelacion(string $llaveOrganizacion, string $facturaId): EstadoCancelacion
    {
        return EstadoCancelacion::Aceptada;
    }
}
