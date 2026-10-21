<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Bandeja;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Meta para la bandeja de cada negocio (ADR 0200), por HTTP y sin paquetes.
 *
 * - WhatsApp: el negocio conecta su número con el registro insertado de Meta (Embedded
 *   Signup, la plataforma como Tech Provider). El código se canjea por una llave de la
 *   app de la plataforma, que se suscribe a su cuenta y registra el número.
 * - Instagram: «Instagram API con inicio de sesión de Instagram». Se pide solo leer el
 *   perfil y manejar los mensajes; la llave larga dura 60 días y se renueva.
 */
class ClienteMetaBandeja
{
    private const ALCANCES_INSTAGRAM = 'instagram_business_basic,instagram_business_manage_messages';

    public function whatsappConfigurado(): bool
    {
        return $this->c('meta_app_id') !== '' && $this->c('meta_app_secret') !== '' && $this->c('whatsapp_config_id') !== '';
    }

    public function instagramConfigurado(): bool
    {
        return $this->c('instagram_app_id') !== '' && $this->c('instagram_app_secret') !== '';
    }

    /**
     * Lo que la web necesita para abrir el registro insertado (nada secreto).
     *
     * @return array{app_id: string, config_id: string, version: string}
     */
    public function datosRegistroWhatsApp(): array
    {
        return ['app_id' => $this->c('meta_app_id'), 'config_id' => $this->c('whatsapp_config_id'), 'version' => $this->version()];
    }

    /**
     * Conecta el número: canjea el código, suscribe la app a su cuenta y registra el
     * número para la API con el PIN dado.
     *
     * @return array{token: string, nombre: string|null}
     */
    public function conectarWhatsApp(string $codigo, string $wabaId, string $numeroId, string $pin): array
    {
        $token = (string) Http::timeout(15)->get($this->graph('oauth/access_token'), [
            'client_id' => $this->c('meta_app_id'),
            'client_secret' => $this->c('meta_app_secret'),
            'code' => $codigo,
        ])->throw()->json('access_token');
        if ($token === '') {
            throw new RuntimeException('Meta no dio la llave del número.');
        }
        $this->api($token)->post($this->graph($wabaId.'/subscribed_apps'))->throw();
        $registro = $this->api($token)->post($this->graph($numeroId.'/register'), ['messaging_product' => 'whatsapp', 'pin' => $pin]);
        // Un número que ya estaba registrado con la API responde con error; no impide usarlo.
        if (! $registro->successful() && $registro->status() >= 500) {
            $registro->throw();
        }
        $numero = (array) $this->api($token)->get($this->graph($numeroId), ['fields' => 'display_phone_number,verified_name'])->json();

        return ['token' => $token, 'nombre' => is_string($numero['display_phone_number'] ?? null) ? $numero['display_phone_number'] : null];
    }

    /** Deja de recibir sus mensajes (no toca su número). */
    public function desconectarWhatsApp(string $token, string $wabaId): void
    {
        $this->api($token)->delete($this->graph($wabaId.'/subscribed_apps'));
    }

    /** Manda texto libre (dentro de las 24 horas); devuelve el id del mensaje. */
    public function enviarWhatsApp(string $token, string $numeroId, string $para, string $texto): string
    {
        return (string) $this->api($token)->post($this->graph($numeroId.'/messages'), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $para,
            'type' => 'text',
            'text' => ['body' => $texto, 'preview_url' => false],
        ])->throw()->json('messages.0.id');
    }

    public function urlAutorizacionInstagram(string $estado, string $vuelta): string
    {
        return 'https://www.instagram.com/oauth/authorize?'.http_build_query([
            'client_id' => $this->c('instagram_app_id'),
            'redirect_uri' => $vuelta,
            'response_type' => 'code',
            'scope' => self::ALCANCES_INSTAGRAM,
            'state' => $estado,
        ]);
    }

    /**
     * Canjea el código por la llave larga (60 días), lee la cuenta y se suscribe a sus
     * mensajes.
     *
     * @return array{token: string, vence_en: CarbonImmutable, cuenta_id: string, nombre: string|null}
     */
    public function conectarInstagram(string $codigo, string $vuelta): array
    {
        $corta = (array) Http::asForm()->timeout(15)->post('https://api.instagram.com/oauth/access_token', [
            'client_id' => $this->c('instagram_app_id'),
            'client_secret' => $this->c('instagram_app_secret'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $vuelta,
            'code' => $codigo,
        ])->throw()->json();
        // Meta lo da suelto o dentro de `data`.
        $corta = is_array($corta['data'][0] ?? null) ? $corta['data'][0] : $corta;
        $tokenCorto = (string) ($corta['access_token'] ?? '');
        if ($tokenCorto === '') {
            throw new RuntimeException('Instagram no dio la llave de la cuenta.');
        }
        $larga = (array) Http::timeout(15)->get('https://graph.instagram.com/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $this->c('instagram_app_secret'),
            'access_token' => $tokenCorto,
        ])->throw()->json();
        $token = (string) ($larga['access_token'] ?? '');
        $yo = (array) $this->api($token)->get($this->instagram('me'), ['fields' => 'user_id,username'])->throw()->json();
        $this->api($token)->post($this->instagram('me/subscribed_apps').'?subscribed_fields=messages')->throw();

        return [
            'token' => $token,
            'vence_en' => CarbonImmutable::now()->addSeconds((int) ($larga['expires_in'] ?? 5184000)),
            'cuenta_id' => (string) ($yo['user_id'] ?? $corta['user_id'] ?? ''),
            'nombre' => is_string($yo['username'] ?? null) ? '@'.$yo['username'] : null,
        ];
    }

    /**
     * Renueva la llave larga de Instagram (otros 60 días).
     *
     * @return array{token: string, vence_en: CarbonImmutable}
     */
    public function renovarInstagram(string $token): array
    {
        $json = (array) Http::timeout(15)->get('https://graph.instagram.com/refresh_access_token', [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $token,
        ])->throw()->json();

        return [
            'token' => (string) ($json['access_token'] ?? $token),
            'vence_en' => CarbonImmutable::now()->addSeconds((int) ($json['expires_in'] ?? 5184000)),
        ];
    }

    /** Manda texto (dentro de las 24 horas); devuelve el id del mensaje. */
    public function enviarInstagram(string $token, string $para, string $texto): string
    {
        return (string) $this->api($token)->post($this->instagram('me/messages'), [
            'recipient' => ['id' => $para],
            'message' => ['text' => $texto],
        ])->throw()->json('message_id');
    }

    /** El nombre de quien escribe por Instagram, si Meta lo da. */
    public function nombreEnInstagram(string $token, string $quien): ?string
    {
        $json = $this->api($token)->get($this->instagram($quien), ['fields' => 'name,username']);
        if (! $json->successful()) {
            return null;
        }
        $nombre = $json->json('name') ?: ($json->json('username') !== null ? '@'.$json->json('username') : null);

        return is_string($nombre) && $nombre !== '' ? $nombre : null;
    }

    /**
     * ¿La firma del aviso es de una de nuestras apps? Null si no hay secreto con qué
     * revisarla.
     */
    public function firmaValida(string $cuerpo, ?string $firma, string $canal): ?bool
    {
        $secretos = array_values(array_filter($canal === 'instagram'
            ? [$this->c('instagram_app_secret'), $this->c('meta_app_secret')]
            : [$this->c('meta_app_secret')]));
        if ($secretos === []) {
            return null;
        }
        if ($firma === null || ! str_starts_with($firma, 'sha256=')) {
            return false;
        }
        foreach ($secretos as $secreto) {
            if (hash_equals('sha256='.hash_hmac('sha256', $cuerpo, $secreto), $firma)) {
                return true;
            }
        }

        return false;
    }

    public function tokenDeVerificacionValido(string $token): bool
    {
        $esperado = $this->c('verify_token');

        return $esperado !== '' && hash_equals($esperado, $token);
    }

    private function api(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(15);
    }

    private function graph(string $ruta): string
    {
        return 'https://graph.facebook.com/'.$this->version().'/'.$ruta;
    }

    private function instagram(string $ruta): string
    {
        return 'https://graph.instagram.com/'.$this->version().'/'.$ruta;
    }

    private function version(): string
    {
        return $this->c('version') ?: 'v23.0';
    }

    private function c(string $clave): string
    {
        return (string) config('agendauno.bandeja.'.$clave, '');
    }
}
