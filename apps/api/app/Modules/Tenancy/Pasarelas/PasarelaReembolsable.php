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
     * Solicita a la pasarela devolver `montoMinor` del pago dado.
     *
     * @param  array<string, string>  $llaves  credenciales del estudio (descifradas)
     */
    public function reembolsar(PagoTenant $pago, int $montoMinor, array $llaves): ResultadoPago;
}
