<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas\Stripe;

use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP mínimo de Stripe (sin SDK). Usa la secret key del tenant para
 * crear PaymentIntents contra la API REST. Testeable con `Http::fake`.
 */
class ClienteStripe
{
    private const BASE = 'https://api.stripe.com/v1';

    public function __construct(private readonly string $secretKey) {}

    /**
     * Crea una sesión de Stripe Checkout: la página de pago alojada por Stripe (tarjeta
     * con 3D Secure u OXXO). El cliente se redirige a `url`; al terminar Stripe lo
     * regresa a `exito` o `cancelado`, y el webhook confirma el pago con el `id`.
     *
     * @param  array<string, string>  $metadata
     * @return array{id: string, url: string}
     */
    public function crearSesionCheckout(
        int $montoMinor,
        string $moneda,
        string $concepto,
        string $exito,
        string $cancelado,
        ?string $metodo = null,
        ?string $email = null,
        array $metadata = [],
    ): array {
        $datos = [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($moneda),
                    'unit_amount' => $montoMinor,
                    'product_data' => ['name' => $concepto],
                ],
            ]],
            'payment_method_types' => $metodo === 'oxxo' ? ['oxxo'] : ['card'],
            'success_url' => $exito,
            'cancel_url' => $cancelado,
            'metadata' => $metadata,
        ];
        if ($email !== null && $email !== '') {
            $datos['customer_email'] = $email;
        }

        $respuesta = Http::withToken($this->secretKey)
            ->asForm()
            ->post(self::BASE.'/checkout/sessions', $datos)
            ->throw();

        /** @var array{id?: string, url?: string} $json */
        $json = $respuesta->json();

        return [
            'id' => (string) ($json['id'] ?? ''),
            'url' => (string) ($json['url'] ?? ''),
        ];
    }

    /**
     * Crea un PaymentIntent y devuelve su id, estado y client_secret (que el
     * cliente usa con Stripe.js para confirmar el pago).
     *
     * @return array{id: string, status: string, client_secret: string}
     */
    public function crearPaymentIntent(int $montoMinor, string $moneda, ?string $metodo): array
    {
        $tipos = $metodo === 'oxxo' ? ['oxxo'] : ['card'];

        $respuesta = Http::withToken($this->secretKey)
            ->asForm()
            ->post(self::BASE.'/payment_intents', [
                'amount' => $montoMinor,
                'currency' => strtolower($moneda),
                'payment_method_types' => $tipos,
            ])
            ->throw();

        /** @var array{id?: string, status?: string, client_secret?: string} $json */
        $json = $respuesta->json();

        return [
            'id' => (string) ($json['id'] ?? ''),
            'status' => (string) ($json['status'] ?? ''),
            'client_secret' => (string) ($json['client_secret'] ?? ''),
        ];
    }
}
