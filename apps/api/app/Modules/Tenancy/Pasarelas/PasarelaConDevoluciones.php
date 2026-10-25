<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\PagoTenant;

/**
 * Pasarela a la que se le puede preguntar qué devoluciones ya se hicieron de un cobro,
 * también las hechas en su propio panel (ADR 0209). Sirve para registrarlas cuando su
 * aviso no llegó. Solo lee.
 */
interface PasarelaConDevoluciones
{
    /**
     * Las devoluciones ya hechas (exitosas) del cobro de un pago aprobado: su id en la
     * pasarela, el monto y la moneda; con `llave`, la de una que pidió la plataforma
     * (Stripe la guarda en su metadata: `reembolso_<ulid>`).
     *
     * @param  array<string, string>  $llaves  credenciales del estudio (descifradas)
     * @return list<array{id: string, monto_minor: int, moneda: string, llave: string|null}>
     */
    public function devoluciones(PagoTenant $pago, array $llaves): array;
}
