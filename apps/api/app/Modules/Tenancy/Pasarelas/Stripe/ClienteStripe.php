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
