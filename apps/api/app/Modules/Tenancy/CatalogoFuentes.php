<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Tipos de letra que cada usuario puede elegir para el panel (Apariencia). Pocos y
 * conocidos: tres del equipo, que no se descargan (Segoe UI, la predeterminada; la
 * del sistema operativo, y Century Gothic, que la web solo ofrece si el equipo la
 * tiene), y tres de Google Fonts con los pesos que usa la app (300 a 700), que el
 * front carga solo si se eligen. Se guarda en la cuenta, como el tema. La
 * predeterminada va primero.
 */
final class CatalogoFuentes
{
    public const POR_DEFECTO = 'segoe_ui';

    /**
     * clave => nombre (también la familia en Google Fonts y en CSS; «Sistema» es la
     * del sistema operativo: `system-ui`). Quien tenía Inter (se retiró) ve la
     * predeterminada.
     *
     * @var array<string, string>
     */
    private const FUENTES = [
        'segoe_ui' => 'Segoe UI',
        'sistema' => 'Sistema',
        'open_sans' => 'Open Sans',
        'lato' => 'Lato',
        'poppins' => 'Poppins',
        'century_gothic' => 'Century Gothic',
    ];

    public static function existe(string $clave): bool
    {
        return isset(self::FUENTES[$clave]);
    }

    /**
     * La del usuario o la predeterminada (también si ya no existe).
     *
     * @return array{clave: string, nombre: string}
     */
    public static function resolver(?string $clave): array
    {
        $clave = $clave !== null && self::existe($clave) ? $clave : self::POR_DEFECTO;

        return ['clave' => $clave, 'nombre' => self::FUENTES[$clave]];
    }

    /**
     * Para el selector.
     *
     * @return list<array{clave: string, nombre: string, es_default: bool}>
     */
    public static function disponibles(): array
    {
        $lista = [];
        foreach (self::FUENTES as $clave => $nombre) {
            $lista[] = ['clave' => $clave, 'nombre' => $nombre, 'es_default' => $clave === self::POR_DEFECTO];
        }

        return $lista;
    }
}
