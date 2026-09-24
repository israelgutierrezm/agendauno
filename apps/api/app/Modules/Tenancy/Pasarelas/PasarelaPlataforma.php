<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\CargoRenta;

/**
 * Pasarela de pago de LA PLATAFORMA: cobra la renta del SaaS al dueño usando las
 * llaves globales de AgendaUno (no las del estudio). Espejo, a nivel plataforma, de
 * {@see PasarelaTenant}. El cobro en linea es ASINCRONO: devuelve `pendiente` con la
 * referencia del intento; el webhook de la plataforma confirma despues.
 */
interface PasarelaPlataforma
{
    public function nombre(): string;

    /**
     * @param  array<string, string>  $llaves  credenciales de la plataforma (descifradas)
     */
    public function cobrar(CargoRenta $cargo, array $llaves): ResultadoPago;
}
