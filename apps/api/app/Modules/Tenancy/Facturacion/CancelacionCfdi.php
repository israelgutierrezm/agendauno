<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Facturacion;

/**
 * Cancelar un CFDI con el proveedor (ADR 0180), aparte del timbrado: la misma
 * FacturAPI, su doble de desarrollo y el de producción sin llave.
 */
interface CancelacionCfdi
{
    /**
     * Pide cancelar el CFDI con un motivo del SAT (`01` con la sustituta, `02` o `03`).
     *
     * @throws TimbradoFallido si el proveedor no la acepta
     */
    public function cancelar(string $llaveOrganizacion, string $facturaId, string $motivo, ?string $sustituta = null): EstadoCancelacion;

    /**
     * Cómo va una cancelación que quedó en espera.
     *
     * @throws TimbradoFallido
     */
    public function consultarCancelacion(string $llaveOrganizacion, string $facturaId): EstadoCancelacion;
}
