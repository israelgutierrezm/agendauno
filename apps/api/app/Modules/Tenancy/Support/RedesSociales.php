<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use Illuminate\Validation\ValidationException;

/**
 * Redes sociales y sitio web del perfil público (del negocio o de una sucursal). Se
 * capturan como usuario (`@casa_navaja`) o como enlace, y se guardan como enlace
 * completo. Un enlace de otra red o que no sea http(s) se rechaza: se muestra tal
 * cual en páginas públicas.
 */
final class RedesSociales
{
    /** Orden en que se muestran. */
    public const REDES = ['instagram', 'facebook', 'tiktok', 'youtube', 'sitio_web'];

    /** Dominios válidos de cada red y cómo se arma el enlace desde el usuario. */
    private const DOMINIOS = [
        'instagram' => ['instagram.com'],
        'facebook' => ['facebook.com', 'fb.com'],
        'tiktok' => ['tiktok.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
    ];

    private const DESDE_USUARIO = [
        'instagram' => 'https://www.instagram.com/%s',
        'facebook' => 'https://www.facebook.com/%s',
        'tiktok' => 'https://www.tiktok.com/@%s',
        'youtube' => 'https://www.youtube.com/@%s',
    ];

    private const MAXIMO = 255;

    /**
     * Reglas de validación para el arreglo de redes bajo `$campo`.
     *
     * @return array<string, list<string>>
     */
    public static function reglas(string $campo = 'redes'): array
    {
        $reglas = [$campo => ['nullable', 'array:'.implode(',', self::REDES)]];
        foreach (self::REDES as $red) {
            $reglas["{$campo}.{$red}"] = ['nullable', 'string', 'max:'.self::MAXIMO];
        }

        return $reglas;
    }

    /**
     * De lo capturado a enlaces completos; lo vacío se quita. Null si no queda nada.
     *
     * @param  array<string, mixed>|null  $entrada
     * @return array<string, string>|null
     *
     * @throws ValidationException
     */
    public static function normalizar(?array $entrada, string $campo = 'redes'): ?array
    {
        $redes = [];
        foreach (self::REDES as $red) {
            $valor = trim((string) ($entrada[$red] ?? ''));
            if ($valor === '') {
                continue;
            }
            $url = $red === 'sitio_web' ? self::sitioWeb($valor) : self::red($red, $valor);
            if ($url === null) {
                throw ValidationException::withMessages([
                    "{$campo}.{$red}" => [$red === 'sitio_web'
                        ? 'Escribe la dirección de tu sitio, por ejemplo casanavaja.mx.'
                        : 'Escribe tu usuario (por ejemplo @casa_navaja) o el enlace de tu perfil.'],
                ]);
            }
            $redes[$red] = $url;
        }

        return $redes === [] ? null : $redes;
    }

    /**
     * Para páginas públicas: [{red, url}] en el orden de REDES.
     *
     * @param  array<string, mixed>|null  $redes
     * @return list<array{red: string, url: string}>
     */
    public static function publicas(?array $redes): array
    {
        $lista = [];
        foreach (self::REDES as $red) {
            $url = $redes[$red] ?? null;
            if (is_string($url) && $url !== '') {
                $lista[] = ['red' => $red, 'url' => $url];
            }
        }

        return $lista;
    }

    private static function red(string $red, string $valor): ?string
    {
        if (preg_match('#^https?://#i', $valor) === 1) {
            $host = strtolower((string) parse_url($valor, PHP_URL_HOST));
            foreach (self::DOMINIOS[$red] as $dominio) {
                if ($host === $dominio || str_ends_with($host, '.'.$dominio)) {
                    return self::enlaceSeguro($valor);
                }
            }

            return null;
        }
        $usuario = ltrim($valor, '@');
        if (preg_match('/^[A-Za-z0-9._-]{1,100}$/', $usuario) !== 1) {
            return null;
        }

        return sprintf(self::DESDE_USUARIO[$red], $usuario);
    }

    private static function sitioWeb(string $valor): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $valor) !== 1) {
            $valor = 'https://'.$valor;
        }

        return self::enlaceSeguro($valor);
    }

    /** Solo http(s) con un dominio de verdad; nada de javascript: ni data:. */
    private static function enlaceSeguro(string $valor): ?string
    {
        $esquema = strtolower((string) parse_url($valor, PHP_URL_SCHEME));
        $host = (string) parse_url($valor, PHP_URL_HOST);
        if (! in_array($esquema, ['http', 'https'], true) || ! str_contains($host, '.')
            || filter_var($valor, FILTER_VALIDATE_URL) === false || strlen($valor) > self::MAXIMO) {
            return null;
        }

        return $valor;
    }
}
