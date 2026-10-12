<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Medicion;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google Analytics 4 desde el servidor: Measurement Protocol (ADR 0143).
 *
 * - `POST {ga4_url}/mp/collect?measurement_id=&api_secret=`, JSON con un evento.
 * - El `client_id` es el de la etiqueta del navegador de quien convirtió (no se
 *   inventa): sin él no se manda. La sesión, solo si el evento pasó cerca de cuando se
 *   capturó (si no, sería otra sesión).
 * - `timestamp_micros` es cuándo pasó de verdad (GA4 acepta hasta 72 h atrás).
 * - Consentimiento: `ad_user_data` y `ad_personalization` según la autorización de
 *   publicidad; sin la de analítica ni siquiera se llega aquí.
 * - GA4 responde 2xx aunque el contenido esté mal: «enviado» quiere decir aceptado. Un
 *   4xx no se reintenta igual; 5xx, 429 o sin conexión, sí. Si la conexión se corta
 *   después de mandarlo, solo se reintenta `purchase` (GA4 la deduplica por
 *   `transaction_id`); lo demás podría contarse dos veces.
 * - Nunca lleva nombres, correos, teléfonos ni respuestas: solo IDs internos públicos
 *   (ulids), montos y, fuera de los negocios de salud, el nombre del plan.
 */
class Ga4MeasurementProtocol
{
    /** Minutos alrededor de la captura en que el evento es de la misma sesión. */
    private const MINUTOS_SESION = 30;

    public function enviar(string $medicion, #[\SensitiveParameter] string $secreto, ConversionMedicion $c, ContextoConversion $ctx): ResultadoEnvio
    {
        try {
            $respuesta = Http::timeout((int) config('agendauno.medicion.timeout', 5))
                ->connectTimeout(3)
                ->withoutRedirecting()
                ->withQueryParameters(['measurement_id' => $medicion, 'api_secret' => $secreto])
                ->post($this->base().'/mp/collect', $this->cuerpo($c, $ctx));
        } catch (ConnectionException $e) {
            return ResultadoEnvio::sinRespuesta($e, $c->nombreGa4 === 'purchase', [$secreto]);
        }

        if ($respuesta->successful()) {
            return ResultadoEnvio::enviado($respuesta->status());
        }

        return ResultadoEnvio::error(
            "GA4 respondió {$respuesta->status()}.",
            $respuesta->status() === 429 || $respuesta->serverError(),
            $respuesta->status(),
        );
    }

    /**
     * Prueba la conexión: valida un evento con el servidor de validación de GA4 (no
     * llega a los informes) y manda uno de prueba con `debug_mode`, que se ve en
     * DebugView. GA4 no dice si el secreto es correcto: eso se confirma en DebugView.
     *
     * @return array{ok: bool, mensajes: list<string>}
     */
    public function probar(string $medicion, #[\SensitiveParameter] string $secreto): array
    {
        $cuerpo = [
            'client_id' => random_int(100000000, 999999999).'.'.now()->getTimestamp(),
            'events' => [['name' => 'prueba_conexion', 'params' => ['engagement_time_msec' => 1, 'debug_mode' => 1]]],
        ];
        try {
            $validacion = Http::timeout(10)->connectTimeout(3)->withoutRedirecting()
                ->withQueryParameters(['measurement_id' => $medicion, 'api_secret' => $secreto])
                ->post($this->base().'/debug/mp/collect', $cuerpo);
            $mensajes = collect((array) $validacion->json('validationMessages', []))
                ->map(fn (mixed $m): string => is_array($m) ? (string) ($m['description'] ?? '') : '')
                ->filter()->values()->all();
            if (! $validacion->successful() || $mensajes !== []) {
                return ['ok' => false, 'mensajes' => $mensajes !== [] ? $mensajes : ["GA4 respondió {$validacion->status()}."]];
            }
            $real = Http::timeout(10)->connectTimeout(3)->withoutRedirecting()
                ->withQueryParameters(['measurement_id' => $medicion, 'api_secret' => $secreto])
                ->post($this->base().'/mp/collect', $cuerpo);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'mensajes' => [ResultadoEnvio::sinRespuesta($e, false, [$secreto])->mensaje ?? 'Sin conexión con GA4.']];
        }

        return $real->successful()
            ? ['ok' => true, 'mensajes' => ['GA4 aceptó el evento de prueba. Revisa DebugView en tu propiedad: debe aparecer «prueba_conexion» en unos segundos.']]
            : ['ok' => false, 'mensajes' => ["GA4 respondió {$real->status()}."]];
    }

    /**
     * @return array<string, mixed>
     */
    public function cuerpo(ConversionMedicion $c, ContextoConversion $ctx): array
    {
        $params = ['engagement_time_msec' => 1];
        if ($ctx->gaSessionId !== null && $ctx->capturadoEn !== null
            && abs($c->ocurridoEn->diffInMinutes($ctx->capturadoEn)) <= self::MINUTOS_SESION) {
            $params['session_id'] = $ctx->gaSessionId;
        }
        if ($c->valorMinor !== null && $c->moneda !== null) {
            $params['value'] = ValorMonetario::decimal($c->valorMinor, $c->moneda);
            $params['currency'] = $c->moneda;
        }
        if ($c->transaccion !== null) {
            $params['transaction_id'] = $c->transaccion;
        }
        if ($c->items !== []) {
            $params['items'] = array_map(fn (array $item): array => array_filter([
                'item_id' => $item['id'],
                'item_name' => $c->sensible ? null : (isset($item['nombre']) ? Str::limit($item['nombre'], 100, '') : null),
                'price' => isset($item['precio_minor'], $c->moneda) ? ValorMonetario::decimal($item['precio_minor'], $c->moneda) : null,
                'quantity' => $item['cantidad'],
            ], static fn (mixed $v): bool => $v !== null), $c->items);
        }

        return [
            'client_id' => $ctx->gaClientId,
            'timestamp_micros' => $c->ocurridoEn->getTimestampMs() * 1000,
            'consent' => [
                'ad_user_data' => $ctx->publicidad ? 'GRANTED' : 'DENIED',
                'ad_personalization' => $ctx->publicidad ? 'GRANTED' : 'DENIED',
            ],
            'events' => [['name' => (string) $c->nombreGa4, 'params' => [...$c->parametros, ...$params]]],
        ];
    }

    private function base(): string
    {
        return rtrim((string) config('agendauno.medicion.ga4_url'), '/');
    }
}
