<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones\Push;

use App\Modules\Tenancy\Models\SuscripcionPushWebTenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Manda una notificación al navegador de una suscripción (ADR 0225): el mensaje va
 * cifrado para ese navegador y firmado con las llaves VAPID de la plataforma
 * (`agendauno.push_web`). Solo a los servicios de push conocidos de los navegadores: una
 * suscripción no puede hacer que el servidor llame a cualquier dirección.
 */
class ClienteWebPush
{
    /** Los servicios de push de Chrome/Edge (FCM), Firefox, Safari y Windows. */
    public const SERVICIOS = ['fcm.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

    /** Segundos que el servicio de push guarda el mensaje si el navegador está apagado. */
    private const TTL = 86400;

    public function configurado(): bool
    {
        return $this->publica() !== '' && (string) config('agendauno.push_web.privada') !== '';
    }

    public function publica(): string
    {
        return (string) config('agendauno.push_web.publica');
    }

    /** ¿Es la dirección de un servicio de push de navegador (https)? */
    public static function endpointValido(string $endpoint): bool
    {
        $partes = parse_url($endpoint);
        if (! is_array($partes) || ($partes['scheme'] ?? '') !== 'https' || ! isset($partes['host'])) {
            return false;
        }
        $host = strtolower($partes['host']);
        foreach (self::SERVICIOS as $servicio) {
            if ($host === $servicio || str_ends_with($host, '.'.$servicio)) {
                return true;
            }
        }

        return false;
    }

    /**
     * true si el servicio lo aceptó; false si la suscripción ya no existe (se olvida).
     * Un error pasajero (sin respuesta, 429, 5xx) se lanza para que se reintente.
     *
     * @param  array<string, string>  $datos
     */
    public function enviar(SuscripcionPushWebTenant $suscripcion, string $titulo, string $cuerpo, array $datos): bool
    {
        if (! $this->configurado()) {
            throw new RuntimeException('El push en la web no está configurado.');
        }
        if (! self::endpointValido($suscripcion->endpoint)) {
            return false;
        }

        $mensaje = (string) json_encode(['titulo' => $titulo, 'cuerpo' => $cuerpo, 'datos' => $datos], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cuerpoCifrado = CifradoWebPush::cifrar($mensaje, $suscripcion->p256dh, $suscripcion->auth);
        $autorizacion = CifradoWebPush::autorizacion(
            $suscripcion->endpoint,
            $this->publica(),
            (string) config('agendauno.push_web.privada'),
            (string) config('agendauno.push_web.sujeto'),
            now()->addHours(12)->getTimestamp(),
        );

        try {
            $respuesta = Http::withHeaders([
                'Authorization' => $autorizacion,
                'Content-Encoding' => 'aes128gcm',
                'TTL' => (string) self::TTL,
                'Urgency' => 'normal',
            ])->withBody($cuerpoCifrado, 'application/octet-stream')->timeout(10)->post($suscripcion->endpoint);
        } catch (ConnectionException $e) {
            throw new RuntimeException('El servicio de push del navegador no respondió.', 0, $e);
        }

        if ($respuesta->successful()) {
            return true;
        }
        // Ya no existe, o sus llaves ya no son las de la plataforma: se olvida.
        if (in_array($respuesta->status(), [400, 403, 404, 410], true)) {
            return false;
        }

        throw new RuntimeException('El servicio de push del navegador respondió '.$respuesta->status().'.');
    }
}
