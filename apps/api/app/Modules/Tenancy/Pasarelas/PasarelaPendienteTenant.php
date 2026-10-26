<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\PagoTenant;
use Illuminate\Support\Str;

/**
 * Pago que espera confirmación externa (hoy solo la ventanilla: un depósito o una
 * transferencia que el negocio registra): crea un intento pendiente con una referencia
 * propia que se confirma después. Stripe, Mercado Pago y OpenPay tienen su integración
 * (PasarelaStripeTenant, PasarelaMercadoPagoTenant, PasarelaOpenPayTenant).
 */
class PasarelaPendienteTenant implements PasarelaTenant
{
    public function __construct(private readonly string $nombre) {}

    public function nombre(): string
    {
        return $this->nombre;
    }

    public function cobrar(PagoTenant $pago, array $llaves): ResultadoPago
    {
        return ResultadoPago::pendiente($this->nombre.'_'.Str::lower(Str::random(24)));
    }
}
