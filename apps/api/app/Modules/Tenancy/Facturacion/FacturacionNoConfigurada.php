<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Facturacion;

use App\Modules\Tenancy\Models\ConfiguracionPlataforma;

/**
 * Proveedor de facturación en producción SIN llave de FacturAPI: no timbra ni
 * descarga. Así una instalación real nunca entrega un CFDI simulado (eso es solo de
 * desarrollo y pruebas, {@see FacturacionFalsa}). Las pantallas ya no ofrecen
 * facturar ({@see ConfiguracionPlataforma::facturacionDisponible()});
 * esto es la defensa de fondo.
 */
class FacturacionNoConfigurada implements CancelacionCfdi, ClienteFacturacion, OrganizacionesCfdi
{
    public const MOTIVO = 'La facturación aún no está disponible en la plataforma.';

    public function timbrar(string $llaveOrganizacion, array $factura): ResultadoTimbre
    {
        throw new TimbradoFallido(self::MOTIVO);
    }

    public function descargar(string $llaveOrganizacion, string $facturaId, string $formato): string
    {
        throw new TimbradoFallido(self::MOTIVO);
    }

    public function cancelar(string $llaveOrganizacion, string $facturaId, string $motivo, ?string $sustituta = null): EstadoCancelacion
    {
        throw new TimbradoFallido(self::MOTIVO);
    }

    public function consultarCancelacion(string $llaveOrganizacion, string $facturaId): EstadoCancelacion
    {
        throw new TimbradoFallido(self::MOTIVO);
    }

    public function crearOrganizacion(string $llaveUsuario, string $nombre): string
    {
        throw new TimbradoFallido(self::MOTIVO);
    }

    public function actualizarDatosLegales(string $llaveUsuario, string $organizacion, array $datos): void
    {
        throw new TimbradoFallido(self::MOTIVO);
    }

    public function subirSello(string $llaveUsuario, string $organizacion, string $certificado, string $llave, string $contrasena): void
    {
        throw new TimbradoFallido(self::MOTIVO);
    }

    public function crearLlaveEnVivo(string $llaveUsuario, string $organizacion): string
    {
        throw new TimbradoFallido(self::MOTIVO);
    }

    public function estadoOrganizacion(string $llaveUsuario, string $organizacion): array
    {
        throw new TimbradoFallido(self::MOTIVO);
    }

    public function enviarPorCorreo(string $llaveOrganizacion, string $facturaId, string $correo): void
    {
        throw new TimbradoFallido(self::MOTIVO);
    }
}
