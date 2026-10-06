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
class FacturacionNoConfigurada implements ClienteFacturacion
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
}
