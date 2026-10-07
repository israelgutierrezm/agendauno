<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use Illuminate\Http\Request;

/**
 * URLs de regreso a la web tras pagar en una página externa: la pantalla de origen
 * con `?pago=exito` o `?pago=cancelado`, para que avise cómo quedó el pago. Al
 * autorizar una tarjeta para pagos automáticos el parámetro es `tarjeta`.
 *
 * Se vuelve al mismo sitio desde el que se inició el pago ({@see origen}): quien entró
 * por el subdominio de su negocio (`{slug}.dominio`, el del enlace y el QR) tiene ahí
 * su sesión, que no existe en el dominio raíz.
 */
final class RetornoPago
{
    /**
     * @return array{exito: string, cancelado: string}
     */
    public static function urls(?string $retorno, string $parametro = 'pago'): array
    {
        $ruta = '/'.ltrim($retorno ?? '/', '/');
        $base = self::origen().$ruta;
        $union = str_contains($base, '?') ? '&' : '?';

        return [
            'exito' => $base.$union.$parametro.'=exito',
            'cancelado' => $base.$union.$parametro.'=cancelado',
        ];
    }

    /**
     * Sitio (esquema://host[:puerto]) al que vuelve el cliente: el de la petición que
     * inicia el pago (encabezado `Origin` o, si no viene, `Referer`) cuando es nuestro,
     * es decir, el host de `url_app` o el subdominio de un negocio del dominio base
     * (en producción, solo con https). Cualquier otro, o sin encabezado (la app móvil,
     * un cobro programado), vuelve a `url_app` como siempre.
     */
    public static function origen(?Request $peticion = null): string
    {
        $porOmision = rtrim((string) config('agendauno.url_app'), '/');
        $peticion ??= request();

        $declarado = trim((string) $peticion->headers->get('Origin'));
        if ($declarado === '' || $declarado === 'null') {
            $declarado = trim((string) $peticion->headers->get('Referer'));
        }

        return $declarado !== '' ? (self::admitido($declarado, $porOmision) ?? $porOmision) : $porOmision;
    }

    /**
     * El sitio del encabezado, sin ruta, si es uno de los nuestros; si no, null.
     */
    private static function admitido(string $valor, string $porOmision): ?string
    {
        $partes = parse_url($valor);
        if (! is_array($partes) || ! isset($partes['scheme'], $partes['host'])) {
            return null;
        }
        $esquema = strtolower($partes['scheme']);
        $host = strtolower($partes['host']);
        $puerto = $partes['port'] ?? null;

        $hostApp = strtolower((string) parse_url($porOmision, PHP_URL_HOST));
        $dominio = strtolower(trim((string) config('agendauno.dominio_base'), '.'));
        $esNuestro = ($hostApp !== '' && $host === $hostApp)
            || ($dominio !== '' && preg_match('/^([a-z0-9-]+\.)?'.preg_quote($dominio, '/').'$/', $host) === 1);
        if (! $esNuestro) {
            return null;
        }

        if (app()->isProduction()) {
            // En producción todo va por https, en el puerto de siempre.
            if ($esquema !== 'https' || ($puerto !== null && $puerto !== 443)) {
                return null;
            }

            return 'https://'.$host;
        }
        if (! in_array($esquema, ['http', 'https'], true)) {
            return null;
        }

        return $esquema.'://'.$host.($puerto !== null ? ':'.$puerto : '');
    }
}
