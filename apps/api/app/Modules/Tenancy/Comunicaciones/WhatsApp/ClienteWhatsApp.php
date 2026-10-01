<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones\WhatsApp;

use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Cliente de WhatsApp (Meta Cloud API) de la plataforma: un solo número de WhatsApp
 * Business para toda la plataforma, con dos usos que el superadministrador enciende
 * por separado (cada mensaje cuesta):
 *
 * - `duenos` (ADR 0070): la plataforma con los dueños; verifican su número al
 *   registrarse y aceptan sus avisos.
 * - `negocios` (ADR 0069): cada negocio con sus clientes. Apagado, ningún negocio ve
 *   la opción y sus avisos siguen por correo y push. Encendido, solo lo usan los
 *   negocios que el superadministrador activó en su ficha (ADR 0083).
 *
 * A quien contesta se le responde con texto libre ({@see enviarTexto}), que no es
 * plantilla porque va dentro de las 24 horas de su mensaje.
 *
 * Los mensajes son plantillas aprobadas por Meta ({@see PlantillasWhatsApp}). La
 * configuración vive cifrada en el control plane (`configuracion_plataforma`): los
 * dos interruptores, el identificador del número y el token (nunca se devuelve).
 */
class ClienteWhatsApp
{
    private const CLAVE = 'whatsapp';

    public const IDIOMA = 'es_MX';

    /**
     * ¿Hay número y token para hablar con Meta?
     */
    public function conectado(): bool
    {
        $config = $this->config();

        return $config['phone_number_id'] !== '' && $config['token'] !== '';
    }

    /**
     * ¿Los negocios pueden mandar avisos a sus clientes?
     */
    public function activoParaNegocios(): bool
    {
        return $this->config()['negocios'] && $this->conectado();
    }

    /**
     * ¿Este negocio manda avisos por WhatsApp a sus clientes? La plataforma lo tiene
     * encendido y el superadministrador lo activó en el negocio (ADR 0083).
     */
    public function activoPara(?Estudio $estudio): bool
    {
        return $estudio instanceof Estudio && $estudio->whatsapp_habilitado && $this->activoParaNegocios();
    }

    /**
     * ¿La plataforma verifica y avisa a los dueños por WhatsApp?
     */
    public function activoParaDuenos(): bool
    {
        return $this->config()['duenos'] && $this->conectado();
    }

    /**
     * Lo que ve el superadministrador (sin el token ni el App Secret). Del webhook de
     * estados (ADR 0074): la dirección y el token de verificación que se cargan en
     * Meta.
     *
     * @return array{negocios: bool, duenos: bool, conectado: bool, phone_number_id: string, token_configurado: bool, webhook: array{url: string, token_verificacion: string, app_secret_configurado: bool}}
     */
    public function paraEditar(): array
    {
        $config = $this->config();

        return [
            'negocios' => $config['negocios'],
            'duenos' => $config['duenos'],
            'conectado' => $this->conectado(),
            'phone_number_id' => $config['phone_number_id'],
            'token_configurado' => $config['token'] !== '',
            'webhook' => [
                'url' => route('api.v1.webhooks.whatsapp'),
                'token_verificacion' => $config['verify_token'],
                'app_secret_configurado' => $config['app_secret'] !== '',
            ],
        ];
    }

    /**
     * Guarda la configuración. Un token o App Secret vacío conserva el que ya
     * estaba. El token de verificación del webhook se genera una vez.
     */
    public function guardar(bool $negocios, bool $duenos, string $phoneNumberId, ?string $token, ?string $appSecret = null): void
    {
        $actual = $this->config();
        $conservar = static fn (?string $nuevo, string $anterior): string => is_string($nuevo) && trim($nuevo) !== '' ? trim($nuevo) : $anterior;

        ConfiguracionPlataforma::establecer(self::CLAVE, (string) json_encode([
            'negocios' => $negocios,
            'duenos' => $duenos,
            'phone_number_id' => trim($phoneNumberId),
            'token' => $conservar($token, $actual['token']),
            'app_secret' => $conservar($appSecret, $actual['app_secret']),
            'verify_token' => $actual['verify_token'] !== '' ? $actual['verify_token'] : Str::random(40),
        ]));
    }

    /**
     * ¿Es Meta quien verifica el webhook? El token que mandó es el nuestro.
     */
    public function tokenDeVerificacionValido(string $token): bool
    {
        $esperado = $this->config()['verify_token'];

        return $esperado !== '' && hash_equals($esperado, $token);
    }

    /**
     * Firma del aviso (`X-Hub-Signature-256: sha256=…`, HMAC del cuerpo con el App
     * Secret). Sin App Secret no se puede verificar: null.
     */
    public function firmaValida(string $cuerpo, ?string $firma): ?bool
    {
        $secreto = $this->config()['app_secret'];
        if ($secreto === '') {
            return null;
        }

        return is_string($firma) && hash_equals('sha256='.hash_hmac('sha256', $cuerpo, $secreto), $firma);
    }

    /**
     * Envía una plantilla aprobada. Un error (token vencido, plantilla inexistente,
     * número inválido, red) lanza excepción: el relay reintenta y, si se agotan los
     * intentos, avisa a la plataforma.
     *
     * Devuelve el id del mensaje en Meta (wamid), con el que luego avisa si se
     * entregó, se leyó o falló.
     *
     * @param  list<string>  $parametros  valores de {{1}}, {{2}}, … en orden
     */
    public function enviarPlantilla(string $telefono, string $plantilla, array $parametros, string $idioma = self::IDIOMA): ?string
    {
        $componentes = $parametros === [] ? [] : [[
            'type' => 'body',
            'parameters' => array_map(static fn (string $valor): array => ['type' => 'text', 'text' => $valor], $parametros),
        ]];

        return $this->enviar($telefono, $plantilla, $idioma, $componentes);
    }

    /**
     * Código de verificación con la plantilla de autenticación: Meta pone el texto y
     * el botón «Copiar código», que también lleva el código.
     */
    public function enviarCodigo(string $telefono, string $plantilla, string $codigo): void
    {
        $this->enviar($telefono, $plantilla, self::IDIOMA, [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $codigo]]],
            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $codigo]]],
        ]);
    }

    /**
     * Texto libre, solo para contestar a quien acaba de escribir (ADR 0083): Meta lo
     * permite sin plantilla dentro de las 24 horas de su mensaje.
     *
     * @return string|null el wamid del mensaje
     */
    public function enviarTexto(string $telefono, string $texto): ?string
    {
        return $this->publicar($telefono, [
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $texto],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $componentes
     * @return string|null el wamid del mensaje
     */
    private function enviar(string $telefono, string $plantilla, string $idioma, array $componentes): ?string
    {
        return $this->publicar($telefono, [
            'type' => 'template',
            'template' => [
                'name' => $plantilla,
                'language' => ['code' => $idioma],
                'components' => $componentes,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $contenido
     * @return string|null el wamid del mensaje
     */
    private function publicar(string $telefono, array $contenido): ?string
    {
        if (! $this->conectado()) {
            throw new RuntimeException('WhatsApp no está conectado en la plataforma.');
        }
        $config = $this->config();

        $respuesta = Http::withToken($config['token'])
            ->timeout(10)
            ->post($this->url($config['phone_number_id']), [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $telefono,
                ...$contenido,
            ]);

        if (! $respuesta->successful()) {
            throw new RuntimeException(self::error($respuesta));
        }

        $wamid = $respuesta->json('messages.0.id');

        return is_string($wamid) && $wamid !== '' ? $wamid : null;
    }

    private function url(string $phoneNumberId): string
    {
        $version = (string) config('agendauno.whatsapp.version', 'v23.0');

        return 'https://graph.facebook.com/'.$version.'/'.rawurlencode($phoneNumberId).'/messages';
    }

    /**
     * El error de Meta en una línea: código y mensaje (p. ej. 190 = token vencido,
     * 132001 = la plantilla no existe en ese idioma).
     */
    private static function error(Response $respuesta): string
    {
        $codigo = $respuesta->json('error.code');
        $mensaje = $respuesta->json('error.error_data.details') ?? $respuesta->json('error.message');

        return 'WhatsApp respondió '.$respuesta->status()
            .(is_scalar($codigo) ? ' ('.$codigo.')' : '')
            .(is_string($mensaje) && $mensaje !== '' ? ': '.$mensaje : '');
    }

    /**
     * Se lee cada vez (sin memoria): un proceso largo (cola, relay) ve al momento
     * que el superadministrador lo apagó.
     *
     * @return array{negocios: bool, duenos: bool, phone_number_id: string, token: string, app_secret: string, verify_token: string}
     */
    private function config(): array
    {
        $datos = [];
        try {
            $datos = json_decode((string) ConfiguracionPlataforma::obtener(self::CLAVE), true);
        } catch (Throwable) {
            // Tabla ausente (migraciones no corridas): apagado.
        }
        $datos = is_array($datos) ? $datos : [];

        return [
            // `encendido` era el interruptor único antes de separar a los dueños.
            'negocios' => (bool) ($datos['negocios'] ?? $datos['encendido'] ?? false),
            'duenos' => (bool) ($datos['duenos'] ?? false),
            'phone_number_id' => is_string($datos['phone_number_id'] ?? null) ? $datos['phone_number_id'] : '',
            'token' => is_string($datos['token'] ?? null) ? $datos['token'] : '',
            'app_secret' => is_string($datos['app_secret'] ?? null) ? $datos['app_secret'] : '',
            'verify_token' => is_string($datos['verify_token'] ?? null) ? $datos['verify_token'] : '',
        ];
    }
}
