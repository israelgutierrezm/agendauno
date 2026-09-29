<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones\WhatsApp;

use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Cliente de WhatsApp (Meta Cloud API) de la plataforma (ADR 0069): un solo número
 * de WhatsApp Business para todos los negocios, que el superadministrador enciende o
 * apaga. Cada mensaje cuesta, así que apagado no se genera ni se envía nada y los
 * negocios no ven la opción (sus avisos siguen por correo y push). Los avisos son
 * plantillas aprobadas por Meta ({@see PlantillasWhatsApp}).
 *
 * La configuración vive cifrada en el control plane (`configuracion_plataforma`):
 * encendido, identificador del número y token de acceso (nunca se devuelve).
 */
class ClienteWhatsApp
{
    private const CLAVE = 'whatsapp';

    public const IDIOMA = 'es_MX';

    /**
     * ¿Se pueden mandar avisos por WhatsApp? Encendido y con número y token.
     */
    public function activo(): bool
    {
        $config = $this->config();

        return $config['encendido'] && $config['phone_number_id'] !== '' && $config['token'] !== '';
    }

    /**
     * Lo que ve el superadministrador (sin el token).
     *
     * @return array{encendido: bool, activo: bool, phone_number_id: string, token_configurado: bool}
     */
    public function paraEditar(): array
    {
        $config = $this->config();

        return [
            'encendido' => $config['encendido'],
            'activo' => $this->activo(),
            'phone_number_id' => $config['phone_number_id'],
            'token_configurado' => $config['token'] !== '',
        ];
    }

    /**
     * Guarda la configuración. Un token vacío conserva el que ya estaba.
     */
    public function guardar(bool $encendido, string $phoneNumberId, ?string $token): void
    {
        $actual = $this->config();
        $token = is_string($token) && trim($token) !== '' ? trim($token) : $actual['token'];

        ConfiguracionPlataforma::establecer(self::CLAVE, (string) json_encode([
            'encendido' => $encendido,
            'phone_number_id' => trim($phoneNumberId),
            'token' => $token,
        ]));
    }

    /**
     * Envía una plantilla aprobada. Un error (token vencido, plantilla inexistente,
     * número inválido, red) lanza excepción: el relay reintenta y, si se agotan los
     * intentos, avisa a la plataforma.
     *
     * @param  list<string>  $parametros  valores de {{1}}, {{2}}, … en orden
     */
    public function enviarPlantilla(string $telefono, string $plantilla, array $parametros, string $idioma = self::IDIOMA): void
    {
        if (! $this->activo()) {
            throw new RuntimeException('WhatsApp está apagado en la plataforma.');
        }
        $config = $this->config();

        $componentes = $parametros === [] ? [] : [[
            'type' => 'body',
            'parameters' => array_map(static fn (string $valor): array => ['type' => 'text', 'text' => $valor], $parametros),
        ]];

        $respuesta = Http::withToken($config['token'])
            ->timeout(10)
            ->post($this->url($config['phone_number_id']), [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $telefono,
                'type' => 'template',
                'template' => [
                    'name' => $plantilla,
                    'language' => ['code' => $idioma],
                    'components' => $componentes,
                ],
            ]);

        if (! $respuesta->successful()) {
            throw new RuntimeException(self::error($respuesta));
        }
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
     * @return array{encendido: bool, phone_number_id: string, token: string}
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
            'encendido' => (bool) ($datos['encendido'] ?? false),
            'phone_number_id' => is_string($datos['phone_number_id'] ?? null) ? $datos['phone_number_id'] : '',
            'token' => is_string($datos['token'] ?? null) ? $datos['token'] : '',
        ];
    }
}
