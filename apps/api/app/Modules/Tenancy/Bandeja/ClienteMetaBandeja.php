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
 * - Messenger: la página de Facebook del negocio, con el inicio de sesión de Facebook
 *   de la misma app. La llave de la página sale de una llave larga y no vence.
 */
class ClienteMetaBandeja
{
    private const ALCANCES_INSTAGRAM = 'instagram_business_basic,instagram_business_manage_messages';

    /** Ver sus páginas, suscribirse a una y manejar sus mensajes; nada más. */
    private const ALCANCES_MESSENGER = 'pages_show_list,pages_manage_metadata,pages_messaging';

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

    /**
     * La dirección (válida unos minutos), el tipo y el tamaño de un medio que llegó a
     * WhatsApp.
     *
     * @return array{url: string, mime: string|null, bytes: int|null}
     */
    public function medioWhatsApp(string $token, string $medioId): array
    {
        $json = (array) $this->api($token)->get($this->graph($medioId))->throw()->json();

        return [
            'url' => (string) ($json['url'] ?? ''),
            'mime' => is_string($json['mime_type'] ?? null) ? $json['mime_type'] : null,
            'bytes' => isset($json['file_size']) ? (int) $json['file_size'] : null,
        ];
    }

    /**
     * Baja un archivo (el de WhatsApp pide la llave del número; el de Instagram es una
     * dirección firmada). Null si pesa más de `$maximo` bytes: no se guarda.
     */
    public function bajar(string $url, ?string $token, int $maximo): ?string
    {
        $peticion = $token !== null ? $this->api($token) : Http::timeout(30);
        $respuesta = $peticion->withOptions(['stream' => false])->get($url)->throw();
        $largo = $respuesta->header('Content-Length');
        if ($largo !== '' && (int) $largo > $maximo) {
            return null;
        }
        $cuerpo = $respuesta->body();

        return strlen($cuerpo) > $maximo ? null : $cuerpo;
    }

    /** Sube un archivo al número del negocio; devuelve el id del medio para mandarlo. */
    public function subirWhatsApp(string $token, string $numeroId, string $contenido, string $mime, string $nombre): string
    {
        return (string) $this->api($token)
            ->attach('file', $contenido, $nombre, ['Content-Type' => $mime])
            ->post($this->graph($numeroId.'/media'), ['messaging_product' => 'whatsapp', 'type' => $mime])
            ->throw()->json('id');
    }

    /**
     * Manda una imagen o un documento ya subido (dentro de las 24 horas); devuelve el
     * id del mensaje.
     */
    public function enviarArchivoWhatsApp(string $token, string $numeroId, string $para, string $tipo, string $medioId, ?string $pie, ?string $nombre): string
    {
        $medio = array_filter([
            'id' => $medioId,
            'caption' => $pie,
            // Solo un documento lleva su nombre.
            'filename' => $tipo === 'document' ? $nombre : null,
        ], static fn (?string $v): bool => $v !== null && $v !== '');

        return (string) $this->api($token)->post($this->graph($numeroId.'/messages'), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $para,
            'type' => $tipo,
            $tipo => $medio,
        ])->throw()->json('messages.0.id');
    }

    /**
     * Manda una imagen por Instagram: Meta la toma de `$url` (una dirección firmada y
     * temporal de la plataforma). Devuelve el id del mensaje.
     */
    public function enviarImagenInstagram(string $token, string $para, string $url, bool $agenteHumano = false): string
    {
        return (string) $this->api($token)->post($this->instagram('me/messages'), [
            'recipient' => ['id' => $para],
            'message' => ['attachment' => ['type' => 'image', 'payload' => ['url' => $url]]],
            ...self::etiqueta($agenteHumano),
        ])->throw()->json('message_id');
    }

    /**
     * Las plantillas aprobadas de la cuenta de WhatsApp del negocio, tal como las da
     * Meta (nombre, idioma, categoría y sus partes).
     *
     * @return list<array<string, mixed>>
     */
    public function plantillasWhatsApp(string $token, string $wabaId): array
    {
        $json = (array) $this->api($token)->get($this->graph($wabaId.'/message_templates'), [
            'fields' => 'name,language,status,category,components,parameter_format',
            'status' => 'APPROVED',
            'limit' => 200,
        ])->throw()->json();

        return array_values(array_filter((array) ($json['data'] ?? []), 'is_array'));
    }

    /**
     * Manda una plantilla aprobada (también fuera de las 24 horas) con los valores de
     * su cuerpo en orden; devuelve el id del mensaje.
     *
     * @param  list<string>  $valores
     */
    public function enviarPlantillaWhatsApp(string $token, string $numeroId, string $para, string $nombre, string $idioma, array $valores): string
    {
        $plantilla = ['name' => $nombre, 'language' => ['code' => $idioma]];
        if ($valores !== []) {
            $plantilla['components'] = [[
                'type' => 'body',
                'parameters' => array_map(static fn (string $v): array => ['type' => 'text', 'text' => $v], $valores),
            ]];
        }

        return (string) $this->api($token)->post($this->graph($numeroId.'/messages'), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $para,
            'type' => 'template',
            'template' => $plantilla,
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
    public function enviarInstagram(string $token, string $para, string $texto, bool $agenteHumano = false): string
    {
        return (string) $this->api($token)->post($this->instagram('me/messages'), [
            'recipient' => ['id' => $para],
            'message' => ['text' => $texto],
            ...self::etiqueta($agenteHumano),
        ])->throw()->json('message_id');
    }

    /**
     * Fuera de las 24 horas, Instagram y Messenger aceptan la respuesta de una persona
     * con la etiqueta HUMAN_AGENT (hasta 7 días).
     *
     * @return array<string, string>
     */
    private static function etiqueta(bool $agenteHumano): array
    {
        return $agenteHumano ? ['messaging_type' => 'MESSAGE_TAG', 'tag' => 'HUMAN_AGENT'] : [];
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

    public function messengerConfigurado(): bool
    {
        return (bool) config('agendauno.bandeja.messenger', false) && $this->c('meta_app_id') !== '' && $this->c('meta_app_secret') !== '';
    }

    /** El inicio de sesión de Facebook para elegir la página: solo sus mensajes. */
    public function urlAutorizacionMessenger(string $estado, string $vuelta): string
    {
        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => $this->c('meta_app_id'),
            'redirect_uri' => $vuelta,
            'response_type' => 'code',
            'scope' => self::ALCANCES_MESSENGER,
            'state' => $estado,
        ]);
    }

    /**
     * Canjea el código por la llave larga de quien conectó y lee sus páginas, cada una
     * con su llave (que no vence si sale de una llave larga).
     *
     * @return list<array{id: string, nombre: string, token: string}>
     */
    public function paginasMessenger(string $codigo, string $vuelta): array
    {
        $corta = (string) Http::timeout(15)->get($this->graph('oauth/access_token'), [
            'client_id' => $this->c('meta_app_id'),
            'client_secret' => $this->c('meta_app_secret'),
            'redirect_uri' => $vuelta,
            'code' => $codigo,
        ])->throw()->json('access_token');
        if ($corta === '') {
            throw new RuntimeException('Facebook no dio la llave.');
        }
        $larga = (string) Http::timeout(15)->get($this->graph('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->c('meta_app_id'),
            'client_secret' => $this->c('meta_app_secret'),
            'fb_exchange_token' => $corta,
        ])->throw()->json('access_token');
        $paginas = (array) $this->api($larga !== '' ? $larga : $corta)
            ->get($this->graph('me/accounts'), ['fields' => 'id,name,access_token', 'limit' => 100])->throw()->json('data');

        return array_values(array_filter(array_map(static fn (mixed $p): ?array => is_array($p) && is_string($p['id'] ?? null) && is_string($p['access_token'] ?? null)
            ? ['id' => $p['id'], 'nombre' => (string) ($p['name'] ?? $p['id']), 'token' => $p['access_token']]
            : null, $paginas)));
    }

    /** Recibe los mensajes de la página (y lo que el negocio contesta desde Facebook). */
    public function suscribirPagina(string $token, string $paginaId): void
    {
        $this->api($token)->post($this->graph($paginaId.'/subscribed_apps').'?subscribed_fields=messages,message_echoes')->throw();
    }

    public function desconectarMessenger(string $token, string $paginaId): void
    {
        $this->api($token)->delete($this->graph($paginaId.'/subscribed_apps'));
    }

    /** Manda texto por Messenger; devuelve el id del mensaje. */
    public function enviarMessenger(string $token, string $paginaId, string $para, string $texto, bool $agenteHumano = false): string
    {
        return (string) $this->api($token)->post($this->graph($paginaId.'/messages'), [
            'recipient' => ['id' => $para],
            'messaging_type' => 'RESPONSE',
            'message' => ['text' => $texto],
            ...self::etiqueta($agenteHumano),
        ])->throw()->json('message_id');
    }

    /**
     * Manda una imagen o un archivo por Messenger: Meta lo toma de `$url` (firmada y
     * temporal). Devuelve el id del mensaje.
     */
    public function enviarAdjuntoMessenger(string $token, string $paginaId, string $para, string $tipo, string $url, bool $agenteHumano = false): string
    {
        return (string) $this->api($token)->post($this->graph($paginaId.'/messages'), [
            'recipient' => ['id' => $para],
            'messaging_type' => 'RESPONSE',
            'message' => ['attachment' => ['type' => $tipo, 'payload' => ['url' => $url, 'is_reusable' => false]]],
            ...self::etiqueta($agenteHumano),
        ])->throw()->json('message_id');
    }

    /** El nombre de quien escribe por Messenger, si Meta lo da. */
    public function nombreEnMessenger(string $token, string $quien): ?string
    {
        $json = $this->api($token)->get($this->graph($quien), ['fields' => 'first_name,last_name']);
        if (! $json->successful()) {
            return null;
        }
        $nombre = trim(((string) $json->json('first_name')).' '.((string) $json->json('last_name')));

        return $nombre !== '' ? $nombre : null;
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
