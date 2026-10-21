<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Bandeja;

use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\CanalMensajeria;
use App\Modules\Tenancy\Models\MensajeConversacionTenant;
use App\Modules\Tenancy\Support\DiscosDeArchivos;
use finfo;
use Illuminate\Support\Str;
use Throwable;

/**
 * Los archivos de la bandeja (ADR 0200, ajuste del 2026-10-21).
 *
 * - Lo que llega se baja aparte, en cola, al disco privado del negocio
 *   (`bandeja/{negocio}/…`): de WhatsApp con la llave del número (Meta lo guarda 30
 *   días), de Instagram de su dirección firmada. Lo que pesa más del máximo de la
 *   plataforma no se guarda: el panel dice que se abra en el teléfono.
 * - Se sirve solo por la API, con `mensajes.atender`. En el navegador se abren solo
 *   imágenes comunes; lo demás se descarga (un HTML o un SVG nunca se abre ahí).
 */
class AdjuntosBandejaTenant
{
    /** Lo que se deja mandar: imágenes y PDF (Instagram, solo imágenes). */
    public const PARA_MANDAR = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    /** Lo que se abre en el navegador; lo demás se descarga. */
    private const EN_LINEA = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** Extensión de lo que llega, por su tipo (lo desconocido queda sin extensión). */
    private const EXTENSIONES = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'application/pdf' => 'pdf', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp',
    ];

    public function __construct(
        private readonly ClienteMetaBandeja $meta,
        private readonly ParametrosTenant $parametros,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    public function maximoBytes(): int
    {
        return $this->parametros->entero('bandeja.archivo_max_mb') * 1024 * 1024;
    }

    /**
     * Baja el archivo de un mensaje y lo guarda. Falso si no se pudo o pesa de más
     * (queda la referencia: se puede intentar al abrirlo).
     */
    public function bajar(MensajeConversacionTenant $mensaje): bool
    {
        if ($mensaje->archivo_ruta !== null || $mensaje->adjunto_ref === null || $mensaje->adjunto_ref === '') {
            return $mensaje->archivo_ruta !== null;
        }
        $estudio = $this->gestor->actual();
        $canal = $estudio === null ? null : CanalMensajeria::query()
            ->where('estudio_id', $estudio->getKey())
            ->where('canal', $mensaje->conversacion?->canal)
            ->first();
        if ($canal === null) {
            return false;
        }

        try {
            if ($canal->canal === CanalMensajeria::WHATSAPP) {
                $medio = $this->meta->medioWhatsApp($canal->token, $mensaje->adjunto_ref);
                if ($medio['bytes'] !== null && $medio['bytes'] > $this->maximoBytes()) {
                    return false;
                }
                $contenido = $this->meta->bajar($medio['url'], $canal->token, $this->maximoBytes());
                $mime = $medio['mime'] ?? $mensaje->adjunto_mime;
            } else {
                $contenido = $this->meta->bajar($mensaje->adjunto_ref, null, $this->maximoBytes());
                $mime = $mensaje->adjunto_mime;
            }
        } catch (Throwable $e) {
            report($e);

            return false;
        }
        if ($contenido === null) {
            return false;
        }
        $mime ??= (new finfo(FILEINFO_MIME_TYPE))->buffer($contenido) ?: null;
        $mensaje->forceFill([
            'archivo_ruta' => $this->guardar($contenido, $mime),
            'archivo_bytes' => strlen($contenido),
            'adjunto_mime' => $mime,
        ])->save();

        return true;
    }

    /** Guarda un archivo en el disco privado del negocio; devuelve su ruta. */
    public function guardar(string $contenido, ?string $mime): string
    {
        $extension = self::EXTENSIONES[(string) $mime] ?? null;
        $ruta = 'bandeja/'.$this->gestor->actual()?->getKey().'/'.Str::random(40).($extension !== null ? '.'.$extension : '');
        DiscosDeArchivos::privado()->put($ruta, $contenido);

        return $ruta;
    }

    /** ¿Se abre en el navegador? (Solo imágenes comunes.) */
    public static function enLinea(?string $mime): bool
    {
        return in_array($mime, self::EN_LINEA, true);
    }

    /** El nombre con que se descarga: el que traía o uno por su tipo. */
    public static function nombreDe(MensajeConversacionTenant $mensaje): string
    {
        if ($mensaje->adjunto_nombre !== null && $mensaje->adjunto_nombre !== '') {
            return $mensaje->adjunto_nombre;
        }
        $extension = self::EXTENSIONES[(string) $mensaje->adjunto_mime] ?? null;

        return 'archivo-'.$mensaje->ulid.($extension !== null ? '.'.$extension : '');
    }
}
