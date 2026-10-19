<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * Dónde se guardan los archivos que suben los negocios (ADR 0170). Son dos discos, y
 * cada uno puede ser el volumen del servidor o un almacenamiento compatible con S3:
 *
 * - **Públicos:** lo que se ve en páginas públicas (logos, portadas, fotos de
 *   personas, servicios, sucursales y artículos, imágenes del sitio). Tienen URL.
 * - **Privados:** los documentos (expedientes, firmados, comprobantes). Nunca tienen
 *   URL pública: se descargan por la API con permiso.
 *
 * `public` y `local` son los de siempre; `LEGADO_*` los nombra para mover lo que ya
 * estaba ahí (`agendauno:mover-archivos`).
 */
final class DiscosDeArchivos
{
    public const LEGADO_PUBLICO = 'public';

    public const LEGADO_PRIVADO = 'local';

    public static function nombrePublico(): string
    {
        return (string) config('agendauno.archivos.publicos', self::LEGADO_PUBLICO);
    }

    public static function nombrePrivado(): string
    {
        return (string) config('agendauno.archivos.privados', self::LEGADO_PRIVADO);
    }

    public static function publico(): FilesystemAdapter
    {
        return self::disco(self::nombrePublico());
    }

    public static function privado(): FilesystemAdapter
    {
        return self::disco(self::nombrePrivado());
    }

    /** ¿Ese disco guarda en el servidor? (lo que está en S3 no va en el respaldo de archivos). */
    public static function esDelServidor(string $disco): bool
    {
        return config("filesystems.disks.{$disco}.driver") === 'local';
    }

    /** Las URL de los públicos empiezan así (con «/» al final). */
    public static function prefijoPublico(?string $disco = null): string
    {
        return rtrim(self::disco($disco ?? self::nombrePublico())->url(''), '/').'/';
    }

    /**
     * La ruta dentro del disco público de una URL suya: la del disco configurado o la del
     * volumen de antes (mientras se mueven, ADR 0170). Null si no es de ninguno.
     *
     * @return array{disco: string, ruta: string}|null
     */
    public static function rutaPublica(string $url): ?array
    {
        foreach (array_unique([self::nombrePublico(), self::LEGADO_PUBLICO]) as $disco) {
            $prefijo = self::prefijoPublico($disco);
            if (str_starts_with($url, $prefijo) && strlen($url) > strlen($prefijo)) {
                return ['disco' => $disco, 'ruta' => substr($url, strlen($prefijo))];
            }
        }

        return null;
    }

    public static function disco(string $nombre): FilesystemAdapter
    {
        return Storage::disk($nombre);
    }
}
