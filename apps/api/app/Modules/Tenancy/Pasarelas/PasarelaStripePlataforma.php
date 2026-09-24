<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Pasarelas\Stripe\ClienteStripe;
use Illuminate\Support\Str;

/**
 * Cobro en linea de la renta del SaaS con Stripe, usando la `secret_key` de LA
 * PLATAFORMA. Con llave crea un PaymentIntent real (reusa ClienteStripe, testeable
 * con Http::fake) y devuelve `pendiente` con el client_secret para Stripe.js; el
 * webhook firmado confirma. SIN llave devuelve un intento simulado pendiente: el
 * flujo queda listo para activarse en cuanto la plataforma cargue sus llaves.
 */
class PasarelaStripePlataforma implements PasarelaPlataforma
{
    public function nombre(): string
    {
        return 'stripe';
    }

    public function cobrar(CargoRenta $cargo, array $llaves): ResultadoPago
    {
        $secretKey = $llaves['secret_key'] ?? '';

        if ($secretKey === '') {
            // Listo para llaves: intento simulado pendiente (lo confirma el webhook).
            return ResultadoPago::pendiente('stripe_sim_'.Str::lower(Str::random(24)));
        }

        $retorno = RetornoPago::urls($cargo->retorno ?? '/renta');
        $cargo->loadMissing('estudio');

        $sesion = (new ClienteStripe($secretKey))->crearSesionCheckout(
            $cargo->monto_minor,
            $cargo->moneda,
            'Renta de AgendaUno · '.$cargo->periodo,
            $retorno['exito'],
            $retorno['cancelado'],
            'tarjeta',
            $cargo->estudio?->contacto_email,
            ['cargo_renta' => (string) $cargo->ulid],
        );

        return ResultadoPago::pendiente($sesion['id'], [
            'tipo' => 'redirect',
            'url' => $sesion['url'],
        ]);
    }
}
