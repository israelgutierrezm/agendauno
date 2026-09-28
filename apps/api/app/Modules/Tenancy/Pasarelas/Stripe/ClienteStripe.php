<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas\Stripe;

use App\Modules\Tenancy\Exceptions\CobroNoConcluyente;
use Illuminate\Http\Client\ConnectionException;
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
        ?string $cliente = null,
        bool $guardarTarjeta = false,
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
        if ($cliente !== null && $cliente !== '') {
            $datos['customer'] = $cliente;
        } elseif ($email !== null && $email !== '') {
            $datos['customer_email'] = $email;
        }
        if ($guardarTarjeta) {
            // La tarjeta queda autorizada para los cargos automáticos de cada periodo
            // (solo tarjeta: OXXO no se puede domiciliar).
            $datos['payment_method_types'] = ['card'];
            $datos['payment_intent_data'] = ['setup_future_usage' => 'off_session'];
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
     * Crea el cliente de una persona en la cuenta Stripe del negocio (a él se ligan
     * sus tarjetas guardadas). Devuelve su id (`cus_…`).
     *
     * @param  array<string, string>  $metadata
     */
    public function crearCliente(string $nombre, ?string $email, string $idempotencia, array $metadata = []): string
    {
        $datos = ['name' => $nombre, 'metadata' => $metadata];
        if ($email !== null && $email !== '') {
            $datos['email'] = $email;
        }

        /** @var array{id?: string} $json */
        $json = Http::withToken($this->secretKey)
            ->withHeaders(['Idempotency-Key' => $idempotencia])
            ->asForm()
            ->post(self::BASE.'/customers', $datos)
            ->throw()
            ->json();

        return (string) ($json['id'] ?? '');
    }

    /**
     * Sesión de Checkout en modo `setup`: el cliente autoriza su tarjeta para los
     * cargos automáticos, sin pagar nada ahora.
     *
     * @param  array<string, string>  $metadata
     * @return array{id: string, url: string}
     */
    public function crearSesionGuardado(string $cliente, string $exito, string $cancelado, array $metadata = []): array
    {
        /** @var array{id?: string, url?: string} $json */
        $json = Http::withToken($this->secretKey)
            ->asForm()
            ->post(self::BASE.'/checkout/sessions', [
                'mode' => 'setup',
                'customer' => $cliente,
                'payment_method_types' => ['card'],
                'success_url' => $exito,
                'cancel_url' => $cancelado,
                'metadata' => $metadata,
                'setup_intent_data' => ['metadata' => $metadata],
            ])
            ->throw()
            ->json();

        return [
            'id' => (string) ($json['id'] ?? ''),
            'url' => (string) ($json['url'] ?? ''),
        ];
    }

    /**
     * La tarjeta que el cliente dejó autorizada en una sesión de Checkout: en modo
     * `setup`, o al pagar con `setup_future_usage`. Null si la sesión no guardó una.
     *
     * @return array{metadata: array<string, mixed>, cliente: string, metodo: string, marca: string|null, ultimos4: string|null, expira_mes: int|null, expira_anio: int|null}|null
     */
    public function tarjetaDeSesion(string $sesionId): ?array
    {
        /** @var array<string, mixed> $sesion */
        $sesion = Http::withToken($this->secretKey)
            ->get(self::BASE.'/checkout/sessions/'.rawurlencode($sesionId), [
                'expand' => ['setup_intent.payment_method', 'payment_intent.payment_method'],
            ])
            ->throw()
            ->json();

        $setup = ($sesion['mode'] ?? '') === 'setup';
        $intent = $setup ? ($sesion['setup_intent'] ?? null) : ($sesion['payment_intent'] ?? null);
        if (! is_array($intent)) {
            return null;
        }
        // Un pago normal no deja la tarjeta autorizada para cobrar después.
        if (! $setup && ($intent['setup_future_usage'] ?? null) !== 'off_session') {
            return null;
        }

        $metodo = $intent['payment_method'] ?? null;
        $cliente = $sesion['customer'] ?? ($intent['customer'] ?? null);
        if (is_array($cliente)) {
            $cliente = $cliente['id'] ?? null;
        }
        if (! is_array($metodo) || ! is_string($metodo['id'] ?? null) || ! is_string($cliente) || $cliente === '') {
            return null;
        }
        $tarjeta = is_array($metodo['card'] ?? null) ? $metodo['card'] : [];

        return [
            'metadata' => is_array($sesion['metadata'] ?? null) ? $sesion['metadata'] : [],
            'cliente' => $cliente,
            'metodo' => $metodo['id'],
            'marca' => isset($tarjeta['brand']) ? (string) $tarjeta['brand'] : null,
            'ultimos4' => isset($tarjeta['last4']) ? (string) $tarjeta['last4'] : null,
            'expira_mes' => isset($tarjeta['exp_month']) ? (int) $tarjeta['exp_month'] : null,
            'expira_anio' => isset($tarjeta['exp_year']) ? (int) $tarjeta['exp_year'] : null,
        ];
    }

    /**
     * Cargo automático: cobra sin el cliente presente a su tarjeta guardada. Un
     * rechazo del banco (402) no lanza: vuelve con `status` = `fallido` y su código
     * (`card_declined`, `insufficient_funds`, `authentication_required`…).
     *
     * @param  array<string, string>  $metadata
     * @return array{id: string, status: string, codigo: string|null}
     *
     * @throws CobroNoConcluyente si Stripe no respondió (se reintenta con la misma llave)
     */
    public function cobrarGuardado(
        int $montoMinor,
        string $moneda,
        string $cliente,
        string $metodo,
        string $concepto,
        string $idempotencia,
        array $metadata = [],
    ): array {
        try {
            $respuesta = Http::withToken($this->secretKey)
                ->withHeaders(['Idempotency-Key' => $idempotencia])
                ->asForm()
                ->timeout(40)
                ->post(self::BASE.'/payment_intents', [
                    'amount' => $montoMinor,
                    'currency' => strtolower($moneda),
                    'customer' => $cliente,
                    'payment_method' => $metodo,
                    'off_session' => 'true',
                    'confirm' => 'true',
                    'description' => $concepto,
                    'metadata' => $metadata,
                ]);
        } catch (ConnectionException $e) {
            throw new CobroNoConcluyente('Stripe no respondió.', previous: $e);
        }

        if ($respuesta->serverError() || $respuesta->status() === 429) {
            throw new CobroNoConcluyente('Stripe no respondió.');
        }

        /** @var array<string, mixed> $json */
        $json = $respuesta->json() ?? [];
        if ($respuesta->successful()) {
            return [
                'id' => (string) ($json['id'] ?? ''),
                'status' => (string) ($json['status'] ?? ''),
                'codigo' => null,
            ];
        }

        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $intent = is_array($error['payment_intent'] ?? null) ? $error['payment_intent'] : [];

        return [
            'id' => (string) ($intent['id'] ?? ''),
            'status' => 'fallido',
            'codigo' => (string) ($error['decline_code'] ?? $error['code'] ?? 'rechazado'),
        ];
    }

    /**
     * Desliga una tarjeta del cliente: ya no se le puede cobrar. Si ya estaba
     * desligada, no hay nada que hacer.
     */
    public function desligarMetodo(string $metodo): void
    {
        Http::withToken($this->secretKey)
            ->asForm()
            ->post(self::BASE.'/payment_methods/'.rawurlencode($metodo).'/detach');
    }

    /**
     * Anula un cargo (PaymentIntent) aún sin cobrar. Devuelve false si ya se cobró o
     * el banco lo está procesando.
     */
    public function anularIntent(string $intentId): bool
    {
        /** @var array{status?: string} $json */
        $json = Http::withToken($this->secretKey)
            ->get(self::BASE.'/payment_intents/'.rawurlencode($intentId))
            ->throw()
            ->json();

        $estado = (string) ($json['status'] ?? '');
        if (in_array($estado, ['succeeded', 'processing', 'requires_capture'], true)) {
            return false;
        }
        if ($estado !== 'canceled') {
            Http::withToken($this->secretKey)
                ->asForm()
                ->post(self::BASE.'/payment_intents/'.rawurlencode($intentId).'/cancel')
                ->throw();
        }

        return true;
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

    /**
     * Cómo va una sesión de Checkout: su estado (`open`, `complete`, `expired`), el de
     * su pago (`paid`, `unpaid`, `no_payment_required`) y el de su cobro (el
     * PaymentIntent: `succeeded`, `processing`, `requires_action`, `canceled`…), que
     * dice si un pago en tienda sigue en espera.
     *
     * @return array{estado: string, pago: string, cobro: string}
     */
    public function sesion(string $sesionId): array
    {
        /** @var array{status?: string, payment_status?: string, payment_intent?: string|array{status?: string}|null} $json */
        $json = Http::withToken($this->secretKey)
            ->get(self::BASE.'/checkout/sessions/'.rawurlencode($sesionId), ['expand' => ['payment_intent']])
            ->throw()
            ->json();

        $intent = $json['payment_intent'] ?? null;

        return [
            'estado' => (string) ($json['status'] ?? ''),
            'pago' => (string) ($json['payment_status'] ?? ''),
            'cobro' => is_array($intent) ? (string) ($intent['status'] ?? '') : '',
        ];
    }

    /**
     * Estado de un PaymentIntent (`succeeded`, `processing`, `requires_action`,
     * `requires_payment_method`, `canceled`…).
     */
    public function estadoIntent(string $intentId): string
    {
        /** @var array{status?: string} $json */
        $json = Http::withToken($this->secretKey)
            ->get(self::BASE.'/payment_intents/'.rawurlencode($intentId))
            ->throw()
            ->json();

        return (string) ($json['status'] ?? '');
    }

    /**
     * El PaymentIntent (cobro) que generó una sesión de Checkout ya pagada.
     */
    public function paymentIntentDeSesion(string $sesionId): ?string
    {
        /** @var array{payment_intent?: string|array{id?: string}|null} $json */
        $json = Http::withToken($this->secretKey)
            ->get(self::BASE.'/checkout/sessions/'.rawurlencode($sesionId))
            ->throw()
            ->json();

        $intent = $json['payment_intent'] ?? null;
        if (is_array($intent)) {
            $intent = $intent['id'] ?? null;
        }

        return is_string($intent) && $intent !== '' ? $intent : null;
    }

    /**
     * Devuelve dinero de un cobro. `status`: succeeded | pending | requires_action |
     * failed | canceled (lo pendiente lo resuelve después el webhook `refund.*`).
     *
     * @param  array<string, string>  $metadata
     * @return array{id: string, status: string}
     */
    public function crearReembolso(string $paymentIntent, int $montoMinor, string $idempotencia, array $metadata = []): array
    {
        $respuesta = Http::withToken($this->secretKey)
            ->withHeaders(['Idempotency-Key' => $idempotencia])
            ->asForm()
            ->post(self::BASE.'/refunds', [
                'payment_intent' => $paymentIntent,
                'amount' => $montoMinor,
                'metadata' => $metadata,
            ])
            ->throw();

        /** @var array{id?: string, status?: string} $json */
        $json = $respuesta->json();

        return [
            'id' => (string) ($json['id'] ?? ''),
            'status' => (string) ($json['status'] ?? ''),
        ];
    }

    /**
     * Vence una sesión de Checkout abierta. Devuelve su estado final: `expired` (ya
     * no se puede pagar) o `complete` (ya se pagó).
     */
    public function expirarSesion(string $sesionId): string
    {
        $respuesta = Http::withToken($this->secretKey)
            ->asForm()
            ->post(self::BASE.'/checkout/sessions/'.rawurlencode($sesionId).'/expire');

        if ($respuesta->successful()) {
            return 'expired';
        }

        // No estaba abierta: se consulta cómo quedó.
        /** @var array{status?: string} $json */
        $json = Http::withToken($this->secretKey)
            ->get(self::BASE.'/checkout/sessions/'.rawurlencode($sesionId))
            ->throw()
            ->json();

        return (string) ($json['status'] ?? 'expired');
    }
}
