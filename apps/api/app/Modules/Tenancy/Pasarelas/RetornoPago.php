<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pasarelas;

use App\Modules\Tenancy\Application\DominiosPropios;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\ProductoComercial;
use Illuminate\Http\Request;

/**
 * URLs de regreso a la web tras pagar en una página externa: la pantalla de origen
 * con `?pago=exito`, `?pago=pendiente` (la pasarela aún no lo aprueba: OXXO, revisión)
 * o `?pago=cancelado`, para que avise cómo quedó el pago. Al autorizar una tarjeta
 * para pagos automáticos el parámetro es `tarjeta`.
 *
 * Volver con `exito` no es estar pagado (OpenPay vuelve siempre así, y la URL se puede
 * escribir a mano): con el pago o el cargo en `ref_pago`, la web le pregunta al servidor
 * y solo dice «Pago confirmado» cuando el aviso de la pasarela ya lo aprobó.
 *
 * Se vuelve al mismo sitio desde el que se inició el pago ({@see origen}): quien entró
 * por el subdominio de su negocio (`{slug}.dominio`, el del enlace y el QR) tiene ahí
 * su sesión, que no existe en el dominio raíz.
 */
final class RetornoPago
{
    /**
     * @param  string|null  $referencia  el ULID del pago (o del cargo de renta) que se confirma al volver
     * @return array{exito: string, pendiente: string, cancelado: string}
     */
    public static function urls(?string $retorno, string $parametro = 'pago', ?string $referencia = null): array
    {
        $ruta = '/'.ltrim($retorno ?? '/', '/');
        $base = self::origen().$ruta;
        $union = str_contains($base, '?') ? '&' : '?';
        $ref = $referencia !== null && $referencia !== '' ? '&ref_pago='.rawurlencode($referencia) : '';

        return [
            'exito' => $base.$union.$parametro.'=exito'.$ref,
            'pendiente' => $base.$union.$parametro.'=pendiente'.$ref,
            'cancelado' => $base.$union.$parametro.'=cancelado',
        ];
    }

    /**
     * Sitio (esquema://host[:puerto]) al que vuelve el cliente: el de la petición que
     * inicia el pago (encabezado `Origin` o, si no viene, `Referer`) cuando es nuestro,
     * es decir, la web de un producto o el subdominio de un negocio en el dominio de un
     * producto (en producción, solo con https). Cualquier otro, o sin encabezado (la app
     * móvil, un cobro programado), vuelve a la web del producto del negocio (ADR 0108)
     * o, fuera de un negocio, a `url_app`.
     */
    public static function origen(?Request $peticion = null): string
    {
        $peticion ??= request();
        $estudio = $peticion->attributes->get('estudio');
        $porOmision = $estudio instanceof Estudio
            ? $estudio->producto()->urlWeb()
            : rtrim((string) config('agendauno.url_app'), '/');

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

        $hostsWeb = [strtolower((string) parse_url($porOmision, PHP_URL_HOST))];
        foreach (ProductoComercial::cases() as $producto) {
            $hostsWeb[] = strtolower((string) parse_url($producto->urlWeb(), PHP_URL_HOST));
        }
        // También el dominio propio de un negocio (ADR 0189): su sesión vive ahí.
        $esNuestro = in_array($host, array_filter($hostsWeb), true)
            || ProductoComercial::delHost($host) !== null
            || app(DominiosPropios::class)->estudioDelHost($host) !== null;
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
