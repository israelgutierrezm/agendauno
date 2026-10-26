<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Facturacion;

use Illuminate\Support\Str;

/**
 * Proveedor de facturación FALSO (dev/test y modo no-configurado): simula un timbre
 * determinista sin llamar a FacturAPI. Permite ejercitar todo el pipeline de
 * facturación sin llaves reales.
 */
class FacturacionFalsa implements CancelacionCfdi, ClienteFacturacion, OrganizacionesCfdi
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

    public function crearOrganizacion(string $llaveUsuario, string $nombre): string
    {
        return 'org_fake_'.Str::lower((string) Str::ulid());
    }

    public function actualizarDatosLegales(string $llaveUsuario, string $organizacion, array $datos): void {}

    /** Un sello vacío o sin contraseña se rechaza, como lo haría FacturAPI. */
    public function subirSello(string $llaveUsuario, string $organizacion, string $certificado, string $llave, string $contrasena): void
    {
        if ($certificado === '' || $llave === '' || $contrasena === '') {
            throw new TimbradoFallido('El certificado, la llave o la contraseña del CSD no son válidos.');
        }
    }

    public function crearLlaveEnVivo(string $llaveUsuario, string $organizacion): string
    {
        return 'sk_live_fake_'.Str::lower((string) Str::ulid());
    }

    public function estadoOrganizacion(string $llaveUsuario, string $organizacion): array
    {
        return ['lista' => true, 'pendientes' => []];
    }

    public function enviarPorCorreo(string $llaveOrganizacion, string $facturaId, string $correo): void {}
}
