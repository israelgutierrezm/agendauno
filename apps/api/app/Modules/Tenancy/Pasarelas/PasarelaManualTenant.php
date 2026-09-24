<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\PagoTenant;
use Illuminate\Support\Str;

/**
 * Cobro manual/efectivo registrado por el staff: se aprueba en el momento (el
 * dinero se recibio en caja). Hace el fulfillment de inmediato.
 */
class PasarelaManualTenant implements PasarelaTenant
{
    public function nombre(): string
    {
        return 'manual';
    }

    public function cobrar(PagoTenant $pago, array $llaves): ResultadoPago
    {
        return ResultadoPago::aprobado('manual_'.Str::lower(Str::random(24)));
    }
}
