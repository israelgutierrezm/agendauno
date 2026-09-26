<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\PagoTenant;

/**
 * Contrato OPCIONAL de una pasarela que sabe devolver dinero (refund) en línea. Las
 * pasarelas manuales/efectivo no lo implementan: el dinero se devuelve en caja y la
 * devolución se aprueba en el momento. {@see ReembolsarPagoTenant} solo llama a la
 * pasarela cuando implementa este contrato, y reconcilia el resultado
 * (aprobado/pendiente/fallido) en la devolución.
 */
interface PasarelaReembolsable
{
    /**
     * Solicita a la pasarela devolver `montoMinor` del pago dado. `$idempotencia` es
     * estable por devolución (la misma en cada reintento): la pasarela no devuelve dos
     * veces con la misma.
     *
     * @param  array<string, string>  $llaves  credenciales del estudio (descifradas)
     */
    public function reembolsar(PagoTenant $pago, int $montoMinor, array $llaves, string $idempotencia): ResultadoPago;
}
