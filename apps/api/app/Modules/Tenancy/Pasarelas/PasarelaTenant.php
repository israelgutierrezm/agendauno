<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\PagoTenant;

/**
 * Pasarela de pago tenant-local. Cobra un pago usando las llaves del propio estudio
 * (no globales). Los cobros en linea son ASINCRONOS: devuelven `pendiente` con la
 * referencia del intento; el webhook confirma despues.
 */
interface PasarelaTenant
{
    public function nombre(): string;

    /**
     * @param  array<string, string>  $llaves  credenciales del estudio (descifradas)
     */
    public function cobrar(PagoTenant $pago, array $llaves): ResultadoPago;
}
