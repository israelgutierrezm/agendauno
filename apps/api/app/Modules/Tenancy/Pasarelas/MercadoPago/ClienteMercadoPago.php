<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas\MercadoPago;

use App\Modules\Tenancy\Pasarelas\MontoDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP mínimo de Mercado Pago (sin SDK) con el access token del negocio.
 * Checkout Pro: la preferencia lleva `external_reference` = el ulid de nuestro pago
 * y la notificación por negocio; el estado real siempre se lee de la API (el
 * webhook solo avisa). Montos en pesos con decimales. Testeable con `Http::fake`.
 */
class ClienteMercadoPago
{
    private const BASE = 'https://api.mercadopago.com';

    public function __construct(private readonly string $accessToken) {}

    /**
     * Preferencia de Checkout Pro: la página de pago de Mercado Pago (tarjeta, OXXO,
     * transferencia). Vence en `venceEn` (también el ticket de pago en efectivo).
     *
     * @param  array{success: string, pending: string, failure: string}  $regreso
     * @return array{id: string, url: string}
     */
    public function crearPreferencia(
        int $montoMinor,
        string $moneda,
        string $titulo,
        string $referencia,
        string $notificacion,
        array $regreso,
        ?string $email,
        CarbonInterface $venceEn,
    ): array {
        $datos = [
            'items' => [[
                'id' => $referencia,
                'title' => $titulo,
                'quantity' => 1,
                'currency_id' => strtoupper($moneda),
                'unit_price' => MontoDecimal::desdeCentavos($montoMinor),
            ]],
            'external_reference' => $referencia,
            'notification_url' => $notificacion,
            'back_urls' => $regreso,
            'auto_return' => 'approved',
            'expires' => true,
            'expiration_date_to' => $venceEn->format('Y-m-d\TH:i:s.vP'),
            'date_of_expiration' => $venceEn->format('Y-m-d\TH:i:s.vP'),
            'metadata' => ['pago' => $referencia],
        ];
        if ($email !== null && $email !== '') {
            $datos['payer'] = ['email' => $email];
        }

        /** @var array{id?: string|int, init_point?: string} $json */
        $json = Http::withToken($this->accessToken)
            ->acceptJson()
            ->post(self::BASE.'/checkout/preferences', $datos)
            ->throw()
            ->json();

        return [
            'id' => (string) ($json['id'] ?? ''),
            'url' => (string) ($json['init_point'] ?? ''),
        ];
    }

    /**
     * Un pago (el estado real: `approved`, `pending`, `in_process`, `rejected`,
     * `cancelled`, `refunded`…).
     *
     * @return array<string, mixed>
     */
    public function pago(string $id): array
    {
        /** @var array<string, mixed> $json */
        $json = Http::withToken($this->accessToken)
            ->acceptJson()
            ->get(self::BASE.'/v1/payments/'.rawurlencode($id))
            ->throw()
            ->json();

        return $json;
    }

    /**
     * Los pagos hechos para una referencia (nuestro pago), del más reciente al más
     * antiguo.
     *
     * @return list<array<string, mixed>>
     */
    public function pagosDeReferencia(string $referencia): array
    {
        /** @var array{results?: list<array<string, mixed>>} $json */
        $json = Http::withToken($this->accessToken)
            ->acceptJson()
            ->get(self::BASE.'/v1/payments/search', [
                'external_reference' => $referencia,
                'sort' => 'date_created',
                'criteria' => 'desc',
            ])
            ->throw()
            ->json();

        return $json['results'] ?? [];
    }

    /**
     * Cancela un pago que aún no se cobra (p. ej. un ticket de OXXO sin pagar).
     * Devuelve false si Mercado Pago ya no lo permite (se cobró o se está cobrando).
     */
    public function cancelarPago(string $id): bool
    {
        return Http::withToken($this->accessToken)
            ->acceptJson()
            ->put(self::BASE.'/v1/payments/'.rawurlencode($id), ['status' => 'cancelled'])
            ->successful();
    }

    /**
     * Vence la preferencia: ya no se puede pagar con ella.
     */
    public function expirarPreferencia(string $id): void
    {
        Http::withToken($this->accessToken)
            ->acceptJson()
            ->put(self::BASE.'/checkout/preferences/'.rawurlencode($id), [
                'expires' => true,
                'expiration_date_to' => now()->format('Y-m-d\TH:i:s.vP'),
            ]);
    }

    /**
     * Suscripción sin plan (`preapproval`) en estado pendiente: el cliente la
     * autoriza con su tarjeta en la página de Mercado Pago (`init_point`) y desde
     * entonces Mercado Pago cobra solo cada mes. El `payer_email` debe ser el de la
     * cuenta con que paga; `start_date` solo cuenta si hay `end_date`.
     *
     * @return array{id: string, url: string}
     */
    public function crearSuscripcion(
        string $motivo,
        string $referencia,
        string $email,
        int $montoMinor,
        string $moneda,
        string $regreso,
        CarbonInterface $inicio,
        CarbonInterface $fin,
    ): array {
        /** @var array{id?: string, init_point?: string} $json */
        $json = Http::withToken($this->accessToken)
            ->acceptJson()
            ->post(self::BASE.'/preapproval', [
                'reason' => $motivo,
                'external_reference' => $referencia,
                'payer_email' => $email,
                'back_url' => $regreso,
                'status' => 'pending',
                'auto_recurring' => [
                    'frequency' => 1,
                    'frequency_type' => 'months',
                    'start_date' => $inicio->format('Y-m-d\TH:i:s.vP'),
                    'end_date' => $fin->format('Y-m-d\TH:i:s.vP'),
                    'transaction_amount' => MontoDecimal::desdeCentavos($montoMinor),
                    'currency_id' => strtoupper($moneda),
                ],
            ])
            ->throw()
            ->json();

        return [
            'id' => (string) ($json['id'] ?? ''),
            'url' => (string) ($json['init_point'] ?? ''),
        ];
    }

    /**
     * Una suscripción (`status`: pending | authorized | paused | canceled).
     *
     * @return array<string, mixed>
     */
    public function suscripcion(string $id): array
    {
        /** @var array<string, mixed> $json */
        $json = Http::withToken($this->accessToken)
            ->acceptJson()
            ->get(self::BASE.'/preapproval/'.rawurlencode($id))
            ->throw()
            ->json();

        return $json;
    }

    /**
     * Cancela una suscripción: Mercado Pago deja de cobrar (no se puede deshacer).
     */
    public function cancelarSuscripcion(string $id): void
    {
        Http::withToken($this->accessToken)
            ->acceptJson()
            ->put(self::BASE.'/preapproval/'.rawurlencode($id), ['status' => 'canceled'])
            ->throw();
    }

    /**
     * Las cuotas cobradas de una suscripción (`authorized_payments`), con el pago
     * de cada una.
     *
     * @return list<array<string, mixed>>
     */
    public function cuotasDeSuscripcion(string $id): array
    {
        /** @var array{results?: list<array<string, mixed>>} $json */
        $json = Http::withToken($this->accessToken)
            ->acceptJson()
            ->get(self::BASE.'/authorized_payments/search', ['preapproval_id' => $id])
            ->throw()
            ->json();

        return $json['results'] ?? [];
    }

    /**
     * Una cuota de suscripción (para ubicar su suscripción desde el aviso).
     *
     * @return array<string, mixed>
     */
    public function cuota(string $id): array
    {
        /** @var array<string, mixed> $json */
        $json = Http::withToken($this->accessToken)
            ->acceptJson()
            ->get(self::BASE.'/authorized_payments/'.rawurlencode($id))
            ->throw()
            ->json();

        return $json;
    }

    /**
     * Devuelve dinero de un pago. Estado: `approved`, `in_process`, `rejected`…
     *
     * @return array{id: string, status: string}
     */
    public function reembolsar(string $pagoId, int $montoMinor, string $idempotencia): array
    {
        /** @var array{id?: string|int, status?: string} $json */
        $json = Http::withToken($this->accessToken)
            ->acceptJson()
            ->withHeaders(['X-Idempotency-Key' => $idempotencia])
            ->post(self::BASE.'/v1/payments/'.rawurlencode($pagoId).'/refunds', [
                'amount' => MontoDecimal::desdeCentavos($montoMinor),
            ])
            ->throw()
            ->json();

        return [
            'id' => (string) ($json['id'] ?? ''),
            'status' => (string) ($json['status'] ?? ''),
        ];
    }
}
