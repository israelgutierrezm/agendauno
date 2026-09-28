<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\PagoTenant;

/**
 * Pasarela a la que se le puede preguntar cómo va un intento de cobro, sin tocarlo.
 * Sirve para conciliar cuando su aviso (webhook) no llegó.
 */
interface PasarelaConsultable
{
    /**
     * - aprobado: se cobró; la referencia es la misma que traería su aviso;
     * - pendiente: aún se puede pagar o se está procesando (p. ej. un pago en tienda),
     *   con la referencia vigente del intento;
     * - rechazado: ya no se puede pagar (venció, se canceló o falló).
     *
     * @param  array<string, string>  $llaves  credenciales del estudio (descifradas)
     */
    public function consultar(PagoTenant $pago, array $llaves): ResultadoPago;
}
