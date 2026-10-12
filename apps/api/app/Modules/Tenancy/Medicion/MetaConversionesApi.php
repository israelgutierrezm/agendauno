<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Medicion;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Meta desde el servidor: Conversions API (ADR 0143).
 *
 * - `POST {meta_url}/{version}/{píxel}/events` con un evento y el token en el cuerpo
 *   (nunca en la URL).
 * - `event_id` es el mismo que usa el píxel del navegador para ese resultado: Meta los
 *   deduplica por `event_name` + `event_id` dentro de 48 h. Es estable entre reintentos.
 * - `event_time` es cuándo pasó de verdad, en segundos (Meta rechaza más de 7 días).
 * - `user_data`: solo lo que el navegador permitió (agente, IP, `fbp`, `fbc`) y el id de
 *   visitante con hash. Sin correos ni teléfonos: la correspondencia avanzada no se usa.
 * - Errores: los códigos temporales de la Graph API (1, 2, 4, 17, 341, 368) se
 *   reintentan; 190 es el token. Un corte después de mandarlo no se reintenta: Meta
 *   no deduplica dos envíos del servidor.
 */
class MetaConversionesApi
{
    /** Códigos de la Graph API que piden esperar y reintentar. */
    private const TEMPORALES = [1, 2, 4, 17, 341, 368];

    public function enviar(string $pixel, #[\SensitiveParameter] string $token, ?string $codigoPrueba, ConversionMedicion $c, ContextoConversion $ctx): ResultadoEnvio
    {
        try {
            $respuesta = Http::timeout((int) config('agendauno.medicion.timeout', 5))
                ->connectTimeout(3)
                ->withoutRedirecting()
                ->post($this->url($pixel), array_filter([
                    'data' => [$this->evento($c, $ctx)],
                    'access_token' => $token,
                    'test_event_code' => $codigoPrueba,
                ], static fn (mixed $v): bool => $v !== null && $v !== ''));
        } catch (ConnectionException $e) {
            return ResultadoEnvio::sinRespuesta($e, false, [$token]);
        }

        if ($respuesta->successful()) {
            return ResultadoEnvio::enviado($respuesta->status());
        }

        return $this->fallo($respuesta);
    }

    /**
     * Prueba la conexión: lee el píxel con el token (confirma que existe y que el token
     * tiene acceso) y, con un código de prueba, manda un evento que se ve en «Eventos de
     * prueba» del administrador de eventos.
     *
     * @return array{ok: bool, mensajes: list<string>}
     */
    public function probar(string $pixel, #[\SensitiveParameter] string $token, ?string $codigoPrueba): array
    {
        try {
            $lectura = Http::timeout(10)->connectTimeout(3)->withoutRedirecting()->withToken($token)
                ->get($this->base()."/{$pixel}", ['fields' => 'id,name']);
            if (! $lectura->successful()) {
                return ['ok' => false, 'mensajes' => [$this->fallo($lectura)->mensaje ?? 'Meta rechazó el token.']];
            }
            $mensajes = ['El token tiene acceso al píxel «'.mb_substr((string) $lectura->json('name', $pixel), 0, 80).'».'];
            if ($codigoPrueba !== null && $codigoPrueba !== '') {
                $prueba = Http::timeout(10)->connectTimeout(3)->withoutRedirecting()->post($this->url($pixel), [
                    'data' => [[
                        'event_name' => 'PruebaConexion',
                        'event_time' => now()->getTimestamp(),
                        'event_id' => 'prueba-'.now()->getTimestampMs(),
                        'action_source' => 'system_generated',
                        'user_data' => ['external_id' => [hash('sha256', 'prueba-conexion')]],
                    ]],
                    'access_token' => $token,
                    'test_event_code' => $codigoPrueba,
                ]);
                if (! $prueba->successful()) {
                    return ['ok' => false, 'mensajes' => [...$mensajes, $this->fallo($prueba)->mensaje ?? 'Meta rechazó el evento de prueba.']];
                }
                $mensajes[] = 'Meta recibió un evento de prueba: búscalo en «Eventos de prueba» del administrador de eventos.';
            }
        } catch (ConnectionException $e) {
            return ['ok' => false, 'mensajes' => [ResultadoEnvio::sinRespuesta($e, false, [$token])->mensaje ?? 'Sin conexión con Meta.']];
        }

        return ['ok' => true, 'mensajes' => $mensajes];
    }

    /**
     * @return array<string, mixed>
     */
    public function evento(ConversionMedicion $c, ContextoConversion $ctx): array
    {
        $evento = [
            'event_name' => (string) $c->nombreMeta,
            'event_time' => $c->ocurridoEn->getTimestamp(),
            'event_id' => $c->idEvento,
            'action_source' => $c->accion,
            'user_data' => array_filter([
                'client_ip_address' => $ctx->ip,
                'client_user_agent' => $ctx->agente,
                'fbp' => $ctx->fbp,
                'fbc' => $ctx->fbc,
                'external_id' => $ctx->visitante !== null ? [hash('sha256', $ctx->visitante)] : null,
            ], static fn (mixed $v): bool => $v !== null),
        ];
        if ($c->accion === 'website' && $ctx->url !== null) {
            $evento['event_source_url'] = $ctx->url;
        }

        $datos = [];
        if ($c->valorMinor !== null && $c->moneda !== null) {
            $datos['value'] = ValorMonetario::decimal($c->valorMinor, $c->moneda);
            $datos['currency'] = $c->moneda;
        }
        if ($c->transaccion !== null) {
            $datos['order_id'] = $c->transaccion;
        }
        // En un negocio de salud, solo el evento estándar: sin qué servicio fue.
        if ($c->items !== [] && ! $c->sensible) {
            $datos['content_ids'] = array_column($c->items, 'id');
            $datos['content_type'] = 'product';
        }
        if ($datos !== []) {
            $evento['custom_data'] = $datos;
        }

        return $evento;
    }

    private function fallo(Response $respuesta): ResultadoEnvio
    {
        $codigo = (int) $respuesta->json('error.code', 0);
        $mensaje = 'Meta respondió '.$respuesta->status().($codigo !== 0 ? " (código {$codigo})" : '').'.';
        if ($codigo === 190) {
            return ResultadoEnvio::error($mensaje.' El token venció o no es válido.', false, $respuesta->status(), 'credenciales');
        }

        return ResultadoEnvio::error(
            $mensaje,
            in_array($codigo, self::TEMPORALES, true) || $respuesta->status() === 429 || $respuesta->serverError(),
            $respuesta->status(),
        );
    }

    private function url(string $pixel): string
    {
        return $this->base()."/{$pixel}/events";
    }

    private function base(): string
    {
        return rtrim((string) config('agendauno.medicion.meta_url'), '/').'/'.config('agendauno.medicion.meta_version');
    }
}
