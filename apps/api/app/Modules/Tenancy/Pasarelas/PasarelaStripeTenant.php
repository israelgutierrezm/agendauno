<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Pasarelas\Stripe\ClienteStripe;
use Illuminate\Support\Str;

/**
 * Cobro en linea con Stripe usando la `secret_key` del estudio. Con llave crea un
 * PaymentIntent real (reusa ClienteStripe, testeable con Http::fake) y devuelve
 * `pendiente` con el client_secret para Stripe.js; el webhook firmado confirma.
 * SIN llave devuelve un intento simulado pendiente: el flujo queda listo para
 * activarse en cuanto el estudio cargue sus llaves.
 */
class PasarelaStripeTenant implements PasarelaTenant
{
    public function nombre(): string
    {
        return 'stripe';
    }

    public function cobrar(PagoTenant $pago, array $llaves): ResultadoPago
    {
        $secretKey = $llaves['secret_key'] ?? '';

        if ($secretKey === '') {
            // Listo para llaves: intento simulado pendiente (lo confirma el webhook).
            return ResultadoPago::pendiente('stripe_sim_'.Str::lower(Str::random(24)));
        }

        $intent = (new ClienteStripe($secretKey))->crearPaymentIntent(
            $pago->monto_minor,
            $pago->moneda,
            $pago->metodo?->value,
        );

        return ResultadoPago::pendiente($intent['id'], [
            'tipo' => 'client_secret',
            'client_secret' => $intent['client_secret'],
        ]);
    }
}
