<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\PagoTenant;

/**
 * Pasarela que puede anular un intento de pago aún abierto (p. ej. una sesión de
 * Checkout), para que al reintentar no queden dos formas de pagar la misma orden.
 */
interface PasarelaCancelable
{
    /**
     * Anula el intento. `true` si ya no se puede pagar (anulado o vencido); `false`
     * si ya se pagó (hay que esperar su confirmación, no cobrar de nuevo).
     *
     * @param  array<string, string>  $llaves
     */
    public function cancelar(PagoTenant $pago, array $llaves): bool;
}
