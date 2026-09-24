<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas\OpenPay;

use App\Modules\Tenancy\Pasarelas\MontoDecimal;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP mínimo de OpenPay (sin SDK): HTTP Basic con la llave privada del
 * negocio (contraseña vacía), montos en pesos con decimales y la IP del cliente en
 * `X-Forwarded-For` (OpenPay la exige para su antifraude). `order_id` = nuestro
 * pago: OpenPay no admite dos cargos con el mismo, así no se cobra dos veces.
 */
class ClienteOpenPay
{
    public function __construct(
        private readonly string $merchantId,
        private readonly string $privateKey,
        private readonly bool $sandbox,
        private readonly string $ipCliente = '127.0.0.1',
    ) {}

    /**
     * Cargo con tarjeta en la página de OpenPay (el cliente captura su tarjeta
     * allá, con 3D Secure si su banco lo pide). Devuelve el cargo y la URL a donde
     * se le manda.
     *
     * @param  array{name: string, last_name?: string, email: string, phone_number?: string}  $cliente
     * @return array{id: string, url: string}
     */
    public function crearCargoConPagina(int $montoMinor, string $moneda, string $descripcion, string $ordenId, array $cliente, string $regreso): array
    {
        /** @var array{id?: string, payment_method?: array{url?: string}} $json */
        $json = $this->http()
            ->post($this->base().'/charges', [
                'method' => 'card',
                'amount' => MontoDecimal::desdeCentavos($montoMinor),
                'currency' => strtoupper($moneda),
                'description' => $descripcion,
                'order_id' => $ordenId,
                'customer' => $cliente,
                'confirm' => false,
                'send_email' => false,
                'redirect_url' => $regreso,
            ])
            ->throw()
            ->json();

        return [
            'id' => (string) ($json['id'] ?? ''),
            'url' => (string) ($json['payment_method']['url'] ?? ''),
        ];
    }

    /**
     * Pago en tienda (OXXO, 7-Eleven y demás de Paynet): referencia y código de
     * barras que el cliente lleva a la tienda antes de `venceEn`.
     *
     * @param  array{name: string, last_name?: string, email: string, phone_number?: string}  $cliente
     * @return array{id: string, referencia: string, codigo_barras: string}
     */
    public function crearCargoEnTienda(int $montoMinor, string $moneda, string $descripcion, string $ordenId, array $cliente, CarbonInterface $venceEn): array
    {
        /** @var array{id?: string, payment_method?: array{reference?: string, barcode_url?: string}} $json */
        $json = $this->http()
            ->post($this->base().'/charges', [
                'method' => 'store',
                'amount' => MontoDecimal::desdeCentavos($montoMinor),
                'currency' => strtoupper($moneda),
                'description' => $descripcion,
                'order_id' => $ordenId,
                'customer' => $cliente,
                'due_date' => $venceEn->toIso8601String(),
            ])
            ->throw()
            ->json();

        return [
            'id' => (string) ($json['id'] ?? ''),
            'referencia' => (string) ($json['payment_method']['reference'] ?? ''),
            'codigo_barras' => (string) ($json['payment_method']['barcode_url'] ?? ''),
        ];
    }

    /**
     * Recibo en PDF de un pago en tienda (lo aloja OpenPay).
     */
    public function reciboDeTienda(string $referencia): string
    {
        $tablero = $this->sandbox ? 'https://sandbox-dashboard.openpay.mx' : 'https://dashboard.openpay.mx';

        return "{$tablero}/paynet-pdf/{$this->merchantId}/{$referencia}";
    }

    /**
     * Un cargo (estado real: `completed`, `charge_pending`, `in_progress`, `failed`,
     * `cancelled`, `refunded`…).
     *
     * @return array<string, mixed>
     */
    public function cargo(string $id): array
    {
        /** @var array<string, mixed> $json */
        $json = $this->http()
            ->get($this->base().'/charges/'.rawurlencode($id))
            ->throw()
            ->json();

        return $json;
    }

    /**
     * Devuelve dinero de un cargo con tarjeta (OpenPay no devuelve pagos en tienda).
     * Devuelve la devolución creada.
     *
     * @return array{id: string, status: string}
     */
    public function reembolsar(string $cargoId, int $montoMinor, string $descripcion): array
    {
        /** @var array{refund?: array{id?: string, status?: string}} $json */
        $json = $this->http()
            ->post($this->base().'/charges/'.rawurlencode($cargoId).'/refund', [
                'description' => $descripcion,
                'amount' => MontoDecimal::desdeCentavos($montoMinor),
            ])
            ->throw()
            ->json();

        return [
            'id' => (string) ($json['refund']['id'] ?? ''),
            'status' => (string) ($json['refund']['status'] ?? ''),
        ];
    }

    /**
     * Cliente de OpenPay (a él se ligan su tarjeta y su suscripción). `external_id`
     * es nuestra referencia: OpenPay no admite dos clientes con la misma.
     *
     * @param  array{name: string, last_name?: string, email: string, phone_number?: string}  $cliente
     */
    public function crearCliente(array $cliente, string $referencia): string
    {
        /** @var array{id?: string} $json */
        $json = $this->http()
            ->post($this->base().'/customers', [...$cliente, 'external_id' => $referencia, 'requires_account' => false])
            ->throw()
            ->json();

        return (string) ($json['id'] ?? '');
    }

    /**
     * Guarda en el cliente la tarjeta que se capturó con OpenPay.js (token de un solo
     * uso + la sesión del dispositivo del antifraude). Nunca pasa el número por aquí.
     *
     * @return array{id: string, marca: string|null, ultimos4: string|null, expira_mes: int|null, expira_anio: int|null}
     */
    public function guardarTarjeta(string $clienteId, string $token, string $sesionDispositivo): array
    {
        /** @var array<string, mixed> $json */
        $json = $this->http()
            ->post($this->base().'/customers/'.rawurlencode($clienteId).'/cards', [
                'token_id' => $token,
                'device_session_id' => $sesionDispositivo,
            ])
            ->throw()
            ->json();

        $numero = (string) ($json['card_number'] ?? '');
        $anio = isset($json['expiration_year']) ? (int) $json['expiration_year'] : null;

        return [
            'id' => (string) ($json['id'] ?? ''),
            'marca' => isset($json['brand']) ? (string) $json['brand'] : null,
            'ultimos4' => $numero !== '' ? substr($numero, -4) : null,
            'expira_mes' => isset($json['expiration_month']) ? (int) $json['expiration_month'] : null,
            'expira_anio' => $anio !== null && $anio < 100 ? 2000 + $anio : $anio,
        ];
    }

    /**
     * Plan mensual por un monto (OpenPay no deja cambiar el monto de un plan).
     */
    public function crearPlanMensual(string $nombre, int $montoMinor, string $moneda): string
    {
        /** @var array{id?: string} $json */
        $json = $this->http()
            ->post($this->base().'/plans', [
                'name' => $nombre,
                'amount' => MontoDecimal::desdeCentavos($montoMinor),
                'currency' => strtoupper($moneda),
                'repeat_every' => 1,
                'repeat_unit' => 'month',
                'retry_times' => 3,
                'status_after_retry' => 'cancelled',
                'trial_days' => 0,
            ])
            ->throw()
            ->json();

        return (string) ($json['id'] ?? '');
    }

    /**
     * Suscribe al cliente al plan con su tarjeta. El primer cobro es el día después
     * de `ultimoDiaSinCobro` (el fin de la "prueba").
     */
    public function crearSuscripcion(string $clienteId, string $planId, string $tarjetaId, CarbonInterface $ultimoDiaSinCobro): string
    {
        /** @var array{id?: string} $json */
        $json = $this->http()
            ->post($this->base().'/customers/'.rawurlencode($clienteId).'/subscriptions', [
                'plan_id' => $planId,
                'source_id' => $tarjetaId,
                'trial_end_date' => $ultimoDiaSinCobro->format('Y-m-d'),
            ])
            ->throw()
            ->json();

        return (string) ($json['id'] ?? '');
    }

    /**
     * Una suscripción (`status`: trial | active | past_due | unpaid | cancelled).
     *
     * @return array<string, mixed>
     */
    public function suscripcion(string $clienteId, string $suscripcionId): array
    {
        /** @var array<string, mixed> $json */
        $json = $this->http()
            ->get($this->base().'/customers/'.rawurlencode($clienteId).'/subscriptions/'.rawurlencode($suscripcionId))
            ->throw()
            ->json();

        return $json;
    }

    /**
     * Cancela la suscripción de inmediato: OpenPay ya no cobra.
     */
    public function cancelarSuscripcion(string $clienteId, string $suscripcionId): void
    {
        $this->http()
            ->delete($this->base().'/customers/'.rawurlencode($clienteId).'/subscriptions/'.rawurlencode($suscripcionId))
            ->throw();
    }

    /**
     * Los cargos recientes del cliente (en la suscripción, los que OpenPay hizo).
     *
     * @return list<array<mixed>>
     */
    public function cargosDeCliente(string $clienteId): array
    {
        $json = $this->http()
            ->get($this->base().'/customers/'.rawurlencode($clienteId).'/charges', ['limit' => 20])
            ->throw()
            ->json();

        $cargos = [];
        foreach (is_array($json) ? $json : [] as $cargo) {
            if (is_array($cargo)) {
                $cargos[] = $cargo;
            }
        }

        return $cargos;
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth($this->privateKey, '')
            ->acceptJson()
            ->withHeaders(['X-Forwarded-For' => $this->ipCliente]);
    }

    private function base(): string
    {
        $host = $this->sandbox ? 'https://sandbox-api.openpay.mx' : 'https://api.openpay.mx';

        return "{$host}/v1/{$this->merchantId}";
    }
}
