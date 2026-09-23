<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Temas visuales de la app (tokens de color que el front aplica como CSS custom
 * properties), al estilo de Acadion. Cada usuario elige el suyo y, si el tema lo
 * permite, ajusta algunos colores para sí; se guarda en su cuenta.
 *
 * Todo tema define el juego COMPLETO de tokens: así ninguno hereda colores sueltos
 * de otro y se ve inconsistente. `oscuro` activa además la variante oscura de la UI
 * (colores de estado e ilustraciones pensados para fondo oscuro).
 */
final class CatalogoTemas
{
    public const POR_DEFECTO = 'agendauno';

    /**
     * Tokens que el usuario puede ajustar para sí (el resto los fija el tema).
     *
     * @var list<string>
     */
    public const PERSONALIZABLES = ['acento', 'barra', 'barra_activo'];

    /**
     * @var array<string, array{nombre: string, oscuro: bool, permite_personalizar: bool, tokens: array<string, string>}>
     */
    private const TEMAS = [
        'agendauno' => [
            'nombre' => 'AgendaUno',
            'oscuro' => false,
            'permite_personalizar' => true,
            'tokens' => [
                'barra' => '#031B4E', 'barra_suave' => '#0B2B62', 'barra_texto' => '#BDC9DE',
                'barra_activo' => '#0070FF', 'barra_activo_texto' => '#FFFFFF',
                'acento' => '#0070FF', 'acento_texto' => '#FFFFFF',
                'fondo' => '#F6F8FC', 'superficie' => '#FFFFFF', 'superficie_2' => '#E9EDF5',
                'borde' => '#D9E0EC', 'texto' => '#031B4E', 'texto_suave' => '#5E6B84',
            ],
        ],
        'agendauno_noche' => [
            'nombre' => 'AgendaUno noche',
            'oscuro' => true,
            'permite_personalizar' => true,
            'tokens' => [
                'barra' => '#020D24', 'barra_suave' => '#0B2854', 'barra_texto' => '#B6C3DA',
                'barra_activo' => '#2D88FF', 'barra_activo_texto' => '#FFFFFF',
                'acento' => '#2D88FF', 'acento_texto' => '#FFFFFF',
                'fondo' => '#020D24', 'superficie' => '#071A3A', 'superficie_2' => '#0B2854',
                'borde' => '#183968', 'texto' => '#FFFFFF', 'texto_suave' => '#B6C3DA',
            ],
        ],
        'oceano' => [
            'nombre' => 'Océano',
            'oscuro' => false,
            'permite_personalizar' => true,
            'tokens' => [
                'barra' => '#00344D', 'barra_suave' => '#00527C', 'barra_texto' => '#B8DCEC',
                'barra_activo' => '#0077B6', 'barra_activo_texto' => '#FFFFFF',
                'acento' => '#006A89', 'acento_texto' => '#FFFFFF',
                'fondo' => '#F2F6F9', 'superficie' => '#FFFFFF', 'superficie_2' => '#E3EDF3',
                'borde' => '#DCE6EC', 'texto' => '#0F2233', 'texto_suave' => '#5A7382',
            ],
        ],
        'indigo' => [
            'nombre' => 'Índigo',
            'oscuro' => false,
            'permite_personalizar' => true,
            'tokens' => [
                'barra' => '#1E1B4B', 'barra_suave' => '#312E81', 'barra_texto' => '#C7D2FE',
                'barra_activo' => '#4F46E5', 'barra_activo_texto' => '#FFFFFF',
                'acento' => '#4F46E5', 'acento_texto' => '#FFFFFF',
                'fondo' => '#F1F5F9', 'superficie' => '#FFFFFF', 'superficie_2' => '#E6EAF3',
                'borde' => '#E2E8F0', 'texto' => '#0F172A', 'texto_suave' => '#64748B',
            ],
        ],
        'medianoche' => [
            'nombre' => 'Medianoche',
            'oscuro' => true,
            'permite_personalizar' => true,
            'tokens' => [
                'barra' => '#0B1120', 'barra_suave' => '#111827', 'barra_texto' => '#94A3B8',
                'barra_activo' => '#38BDF8', 'barra_activo_texto' => '#0B1120',
                'acento' => '#38BDF8', 'acento_texto' => '#0B1120',
                'fondo' => '#0F172A', 'superficie' => '#1E293B', 'superficie_2' => '#273449',
                'borde' => '#334155', 'texto' => '#F1F5F9', 'texto_suave' => '#94A3B8',
            ],
        ],
        'esmeralda' => [
            'nombre' => 'Esmeralda',
            'oscuro' => false,
            'permite_personalizar' => true,
            'tokens' => [
                'barra' => '#064E3B', 'barra_suave' => '#065F46', 'barra_texto' => '#A7F3D0',
                'barra_activo' => '#10B981', 'barra_activo_texto' => '#052E23',
                'acento' => '#059669', 'acento_texto' => '#FFFFFF',
                'fondo' => '#F0FDF4', 'superficie' => '#FFFFFF', 'superficie_2' => '#DCF5E7',
                'borde' => '#D1FAE5', 'texto' => '#052E23', 'texto_suave' => '#4B7A6A',
            ],
        ],
        'rosa_crema' => [
            'nombre' => 'Rosa crema',
            'oscuro' => false,
            'permite_personalizar' => true,
            'tokens' => [
                'barra' => '#6F4E63', 'barra_suave' => '#5B3F52', 'barra_texto' => '#E7D3DA',
                'barra_activo' => '#B76E79', 'barra_activo_texto' => '#FFFFFF',
                'acento' => '#B76E79', 'acento_texto' => '#FFFFFF',
                'fondo' => '#FAF6F2', 'superficie' => '#FFFCF9', 'superficie_2' => '#F1E7E2',
                'borde' => '#E8D8D2', 'texto' => '#3F3438', 'texto_suave' => '#7D6C70',
            ],
        ],
        'alto_contraste' => [
            'nombre' => 'Alto contraste',
            'oscuro' => false,
            // Sin ajustes personales: personalizar rompería el contraste, que es la
            // razón de ser de este tema.
            'permite_personalizar' => false,
            'tokens' => [
                'barra' => '#000000', 'barra_suave' => '#1A1A1A', 'barra_texto' => '#FFFFFF',
                'barra_activo' => '#FFD400', 'barra_activo_texto' => '#000000',
                'acento' => '#0000CC', 'acento_texto' => '#FFFFFF',
                'fondo' => '#FFFFFF', 'superficie' => '#FFFFFF', 'superficie_2' => '#F0F0F0',
                'borde' => '#000000', 'texto' => '#000000', 'texto_suave' => '#333333',
            ],
        ],
    ];

    public static function existe(string $clave): bool
    {
        return isset(self::TEMAS[$clave]);
    }

    public static function permitePersonalizar(string $clave): bool
    {
        return self::TEMAS[$clave]['permite_personalizar'] ?? false;
    }

    /**
     * Lo que ve un usuario: su tema (o el predeterminado) con sus ajustes personales
     * encima, solo si el tema los admite.
     *
     * @param  array<string, string>|null  $personalizacion
     * @return array{clave: string, nombre: string, oscuro: bool, permite_personalizar: bool, tokens: array<string, string>, personalizacion: array<string, string>}
     */
    public static function resolver(?string $clave, ?array $personalizacion): array
    {
        $clave = $clave !== null && self::existe($clave) ? $clave : self::POR_DEFECTO;
        $tema = self::TEMAS[$clave];
        $propios = $tema['permite_personalizar']
            ? array_intersect_key($personalizacion ?? [], array_flip(self::PERSONALIZABLES))
            : [];

        return [
            'clave' => $clave,
            'nombre' => $tema['nombre'],
            'oscuro' => $tema['oscuro'],
            'permite_personalizar' => $tema['permite_personalizar'],
            'tokens' => [...$tema['tokens'], ...$propios],
            'personalizacion' => $propios,
        ];
    }

    /**
     * Temas disponibles con una muestra para pintar la vista previa del selector.
     *
     * @return list<array{clave: string, nombre: string, oscuro: bool, es_default: bool, muestra: array{barra: string, acento: string, fondo: string, superficie: string}}>
     */
    public static function disponibles(): array
    {
        $lista = [];
        foreach (self::TEMAS as $clave => $tema) {
            $lista[] = [
                'clave' => $clave,
                'nombre' => $tema['nombre'],
                'oscuro' => $tema['oscuro'],
                'es_default' => $clave === self::POR_DEFECTO,
                'muestra' => [
                    'barra' => $tema['tokens']['barra'],
                    'acento' => $tema['tokens']['acento'],
                    'fondo' => $tema['tokens']['fondo'],
                    'superficie' => $tema['tokens']['superficie'],
                ],
            ];
        }

        return $lista;
    }
}
