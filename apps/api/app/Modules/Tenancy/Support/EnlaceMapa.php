<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use Closure;

/**
 * Enlace de Google Maps de una sede: el que da «Compartir» en Google Maps
 * (`maps.app.goo.gl/…`) o el de la barra del navegador (`google.com/maps/…`). Solo se
 * aceptan enlaces de Google Maps y se guardan con https: se muestran en páginas
 * públicas para que el cliente llegue a la sede correcta.
 */
final class EnlaceMapa
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function reglas(): array
    {
        return [
            'mapa_url' => ['nullable', 'string', 'max:500', static function (string $atributo, mixed $valor, Closure $falla): void {
                if (is_string($valor) && trim($valor) !== '' && self::normalizar($valor) === null) {
                    $falla('Pega el enlace que da Google Maps al compartir la ubicación de la sede.');
                }
            }],
        ];
    }

    /**
     * El enlace con https, o null si está vacío o no es de Google Maps.
     */
    public static function normalizar(?string $valor): ?string
    {
        $texto = trim((string) $valor);
        if ($texto === '') {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $texto) !== 1) {
            $texto = 'https://'.$texto;
        }

        $partes = parse_url($texto);
        if (! is_array($partes) || ! isset($partes['scheme'], $partes['host']) || isset($partes['user']) || isset($partes['pass'])) {
            return null;
        }
        if (! in_array(strtolower($partes['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($partes['host']);
        $ruta = $partes['path'] ?? '';
        $deMaps = $host === 'maps.app.goo.gl'
            || $host === 'maps.google.com'
            || ($host === 'goo.gl' && str_starts_with($ruta, '/maps'))
            || (preg_match('/^(www\.)?google\.(com|[a-z]{2})(\.[a-z]{2})?$/', $host) === 1 && str_starts_with($ruta, '/maps'));

        return $deMaps ? (string) preg_replace('#^http://#i', 'https://', $texto) : null;
    }
}
