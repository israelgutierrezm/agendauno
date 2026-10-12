<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\SitioWeb;

/**
 * Un video del sitio (ADR 0146): se pega el enlace de YouTube o Vimeo y se guarda solo
 * el proveedor y el id. La página arma el reproductor con eso (sin cookies de YouTube y
 * hasta que la persona le da clic); nunca se guarda ni se inserta código ajeno.
 */
final class VideoSitio
{
    public const PROVEEDORES = ['youtube', 'vimeo'];

    /**
     * @return array{proveedor: string, id: string}|null
     */
    public static function desdeUrl(?string $url): ?array
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $partes = parse_url(trim($url));
        if (! is_array($partes) || ! in_array(strtolower($partes['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower((string) ($partes['host'] ?? ''));
        $ruta = (string) ($partes['path'] ?? '');

        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
            parse_str((string) ($partes['query'] ?? ''), $consulta);
            $id = $ruta === '/watch' ? ($consulta['v'] ?? null)
                : (preg_match('#^/(?:shorts|embed|live)/([^/]+)#', $ruta, $m) === 1 ? $m[1] : null);

            return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1 ? ['proveedor' => 'youtube', 'id' => $id] : null;
        }
        if ($host === 'youtu.be') {
            $id = ltrim($ruta, '/');

            return preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1 ? ['proveedor' => 'youtube', 'id' => $id] : null;
        }
        if (in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)
            && preg_match('#^/(?:video/)?(\d{6,12})/?$#', $ruta, $m) === 1) {
            return ['proveedor' => 'vimeo', 'id' => $m[1]];
        }

        return null;
    }
}
