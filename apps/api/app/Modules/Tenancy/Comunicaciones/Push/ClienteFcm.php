<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones\Push;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente de Firebase Cloud Messaging (API HTTP v1) de la plataforma: la app es una
 * sola para todos los negocios, así que hay un proyecto de Firebase y una cuenta de
 * servicio (`services.fcm.credenciales`). El token de acceso OAuth se obtiene firmando
 * un JWT (RS256) con la llave de la cuenta de servicio y se reutiliza mientras vive.
 */
class ClienteFcm
{
    private const ALCANCE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const CACHE_TOKEN = 'fcm:token-acceso';

    /**
     * @var array{client_email: string, private_key: string, token_uri: string, proyecto: string}|false|null
     */
    private array|false|null $cuenta = null;

    /**
     * ¿La plataforma tiene FCM configurado (cuenta de servicio legible)?
     */
    public function configurado(): bool
    {
        return $this->cuenta() !== null;
    }

    /**
     * Envía una notificación a un dispositivo. Devuelve false si el token ya no sirve
     * (la app se desinstaló o es de otro proyecto): hay que olvidarlo. Un error
     * pasajero (red, límite, servidor) lanza excepción para reintentar después.
     *
     * @param  array<string, string>  $datos  datos para la app (p. ej. a qué pantalla ir)
     */
    public function enviar(string $token, string $titulo, string $cuerpo, array $datos = []): bool
    {
        $cuenta = $this->cuenta() ?? throw new RuntimeException('Las notificaciones push no están configuradas.');

        $respuesta = Http::withToken($this->tokenDeAcceso($cuenta))
            ->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$cuenta['proyecto']}/messages:send", [
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => $titulo, 'body' => $cuerpo],
                    'data' => (object) $datos,
                    'android' => ['priority' => 'high', 'notification' => ['sound' => 'default']],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                ],
            ]);

        if ($respuesta->successful()) {
            return true;
        }
        if (self::tokenInvalido($respuesta)) {
            return false;
        }
        if ($respuesta->status() === 401) {
            // El token de acceso venció o se revocó: el siguiente intento pide otro.
            Cache::forget(self::CACHE_TOKEN);
        }

        throw new RuntimeException('FCM respondió '.$respuesta->status().': '.(string) $respuesta->json('error.status', 'error'));
    }

    /**
     * El dispositivo ya no existe (UNREGISTERED), el token es de otro proyecto
     * (SENDER_ID_MISMATCH) o no es un token válido.
     */
    private static function tokenInvalido(Response $respuesta): bool
    {
        $codigos = collect((array) $respuesta->json('error.details', []))
            ->map(fn (mixed $detalle): string => is_array($detalle) ? (string) ($detalle['errorCode'] ?? '') : '');

        if ($codigos->contains('UNREGISTERED') || $codigos->contains('SENDER_ID_MISMATCH')) {
            return true;
        }

        return $respuesta->status() === 400
            && str_contains(strtolower((string) $respuesta->json('error.message', '')), 'registration token');
    }

    /**
     * @param  array{client_email: string, private_key: string, token_uri: string, proyecto: string}  $cuenta
     */
    private function tokenDeAcceso(array $cuenta): string
    {
        $guardado = Cache::get(self::CACHE_TOKEN);
        if (is_string($guardado) && $guardado !== '') {
            return $guardado;
        }

        $ahora = time();
        $encabezado = self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $reclamos = self::base64Url((string) json_encode([
            'iss' => $cuenta['client_email'],
            'scope' => self::ALCANCE,
            'aud' => $cuenta['token_uri'],
            'iat' => $ahora,
            'exp' => $ahora + 3600,
        ]));
        $firma = '';
        if (! openssl_sign($encabezado.'.'.$reclamos, $firma, $cuenta['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar con la llave de la cuenta de servicio de FCM.');
        }

        $respuesta = Http::asForm()->timeout(10)->post($cuenta['token_uri'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $encabezado.'.'.$reclamos.'.'.self::base64Url($firma),
        ]);
        $token = $respuesta->json('access_token');
        if (! $respuesta->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('Google no entregó el token de acceso de FCM ('.$respuesta->status().').');
        }

        $vida = max(60, (int) $respuesta->json('expires_in', 3600) - 300);
        Cache::put(self::CACHE_TOKEN, $token, $vida);

        return $token;
    }

    /**
     * @return array{client_email: string, private_key: string, token_uri: string, proyecto: string}|null
     */
    private function cuenta(): ?array
    {
        if ($this->cuenta === null) {
            $this->cuenta = self::leerCuenta() ?? false;
        }

        return $this->cuenta === false ? null : $this->cuenta;
    }

    /**
     * @return array{client_email: string, private_key: string, token_uri: string, proyecto: string}|null
     */
    private static function leerCuenta(): ?array
    {
        $ruta = config('services.fcm.credenciales');
        if (! is_string($ruta) || $ruta === '' || ! is_file($ruta)) {
            return null;
        }

        $datos = json_decode((string) file_get_contents($ruta), true);
        if (! is_array($datos)) {
            return null;
        }

        $email = $datos['client_email'] ?? null;
        $llave = $datos['private_key'] ?? null;
        $proyecto = config('services.fcm.proyecto') ?: ($datos['project_id'] ?? null);
        if (! is_string($email) || $email === '' || ! is_string($llave) || $llave === '' || ! is_string($proyecto) || $proyecto === '') {
            return null;
        }

        $tokenUri = $datos['token_uri'] ?? null;

        return [
            'client_email' => $email,
            'private_key' => $llave,
            'token_uri' => is_string($tokenUri) && $tokenUri !== '' ? $tokenUri : 'https://oauth2.googleapis.com/token',
            'proyecto' => $proyecto,
        ];
    }

    private static function base64Url(string $valor): string
    {
        return rtrim(strtr(base64_encode($valor), '+/', '-_'), '=');
    }
}
