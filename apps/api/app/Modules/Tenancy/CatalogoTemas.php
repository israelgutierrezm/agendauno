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
     * @var array<string, array{nombre: string, oscuro: bool, tokens: array<string, string>}>
     */
    private const TEMAS = [
        // Claro: barra lateral blanca, fondo lavanda muy suave y texto pizarra; el
        // azul de la marca solo en acciones y en lo activo.
        'agendauno' => [
            'nombre' => 'AgendaUno',
            'oscuro' => false,
            'tokens' => [
                'barra' => '#FFFFFF', 'barra_suave' => '#F2F4FA', 'barra_texto' => '#5B6478',
                'barra_activo' => '#EAF1FF', 'barra_activo_texto' => '#0B5BD3',
                'barra_borde' => '#E8EBF2', 'barra_titulo' => '#1E2A3B',
                // Con texto blanco encima, al menos 4.5:1 (AA) en este y en todos.
                'acento' => '#006DF7', 'acento_texto' => '#FFFFFF',
                'fondo' => '#F6F7FB', 'superficie' => '#FFFFFF', 'superficie_2' => '#F1F3F8',
                'borde' => '#E6E9F0', 'texto' => '#1E2A3B', 'texto_suave' => '#6B7385',
            ],
        ],
        // Marino y aguamarina: la barra en azul marino con el menú en un gris azulado
        // claro, lo activo en un aguamarina pastel con letra marina, y botones y enlaces
        // en verde azulado. La página en un gris niebla y las letras en el mismo marino.
        // Todo con contraste AA (lo más justo, 4.8:1 de lo suave sobre lo gris).
        'agendauno_alternativo' => [
            'nombre' => 'Agenda Uno Alternativo',
            'oscuro' => false,
            'tokens' => [
                'barra' => '#16324F', 'barra_suave' => '#21456B', 'barra_texto' => '#D3E2F0',
                'barra_activo' => '#A6E3D7', 'barra_activo_texto' => '#0E2A40',
                'barra_borde' => 'rgb(255 255 255 / 10%)', 'barra_titulo' => '#FFFFFF',
                'acento' => '#0F7B76', 'acento_texto' => '#FFFFFF',
                'fondo' => '#F2F6F8', 'superficie' => '#FFFFFF', 'superficie_2' => '#E5EFF1',
                'borde' => '#D3E0E6', 'texto' => '#16324F', 'texto_suave' => '#4F6A80',
            ],
        ],
        // Océano: un azul vivo (#0A6EBD) es el protagonista. Va en la barra, que en el
        // panel enmarca el contenido, y en botones y enlaces; con letra blanca llega a
        // 5.3:1 (AA). Lo activo del menú en blanco con letra azul, la página en un
        // celeste muy claro y las letras en un azul marino.
        'oceano' => [
            'nombre' => 'Océano',
            'oscuro' => false,
            'tokens' => [
                'barra' => '#0A6EBD', 'barra_suave' => '#07528F', 'barra_texto' => '#E3F4FF',
                'barra_activo' => '#FFFFFF', 'barra_activo_texto' => '#0A6EBD',
                'barra_borde' => 'rgb(255 255 255 / 16%)', 'barra_titulo' => '#FFFFFF',
                'acento' => '#0A6EBD', 'acento_texto' => '#FFFFFF',
                'fondo' => '#EEF6FD', 'superficie' => '#FFFFFF', 'superficie_2' => '#E1EFFA',
                'borde' => '#D3E5F4', 'texto' => '#0B2540', 'texto_suave' => '#4A6A85',
            ],
        ],
        'esmeralda' => [
            'nombre' => 'Esmeralda',
            'oscuro' => false,
            'tokens' => [
                'barra' => '#064E3B', 'barra_suave' => '#065F46', 'barra_texto' => '#A7F3D0',
                'barra_activo' => '#10B981', 'barra_activo_texto' => '#052E23',
                'barra_borde' => 'rgb(255 255 255 / 10%)', 'barra_titulo' => '#FFFFFF',
                'acento' => '#04825B', 'acento_texto' => '#FFFFFF',
                'fondo' => '#F0FDF4', 'superficie' => '#FFFFFF', 'superficie_2' => '#DCF5E7',
                'borde' => '#D1FAE5', 'texto' => '#052E23', 'texto_suave' => '#4B7A6A',
            ],
        ],
        'rosa_crema' => [
            'nombre' => 'Rosa crema',
            'oscuro' => false,
            'tokens' => [
                'barra' => '#6F4E63', 'barra_suave' => '#5B3F52', 'barra_texto' => '#E7D3DA',
                'barra_activo' => '#AD5A66', 'barra_activo_texto' => '#FFFFFF',
                'barra_borde' => 'rgb(255 255 255 / 10%)', 'barra_titulo' => '#FFFFFF',
                'acento' => '#AD5A66', 'acento_texto' => '#FFFFFF',
                'fondo' => '#FAF6F2', 'superficie' => '#FFFCF9', 'superficie_2' => '#F1E7E2',
                'borde' => '#E8D8D2', 'texto' => '#3F3438', 'texto_suave' => '#7D6C70',
            ],
        ],
        // El oscuro va al final de la lista.
        'medianoche' => [
            'nombre' => 'Medianoche',
            'oscuro' => true,
            'tokens' => [
                'barra' => '#0B1120', 'barra_suave' => '#111827', 'barra_texto' => '#94A3B8',
                'barra_activo' => '#38BDF8', 'barra_activo_texto' => '#0B1120',
                'barra_borde' => 'rgb(255 255 255 / 10%)', 'barra_titulo' => '#FFFFFF',
                'acento' => '#38BDF8', 'acento_texto' => '#0B1120',
                'fondo' => '#0F172A', 'superficie' => '#1E293B', 'superficie_2' => '#273449',
                'borde' => '#334155', 'texto' => '#F1F5F9', 'texto_suave' => '#94A3B8',
            ],
        ],
    ];

    public static function existe(string $clave): bool
    {
        return isset(self::TEMAS[$clave]);
    }

    /**
     * Lo que ve un usuario: su tema (o el predeterminado) con sus ajustes personales
     * encima, solo si el tema los admite. Un tema que ya no existe (se retiraron
     * "AgendaUno marino", "AgendaUno noche", "Índigo" y "Alto contraste") cae en el
     * predeterminado.
     *
     * Trae también su tipo de letra ({@see CatalogoFuentes}).
     *
     * @param  array<string, string>|null  $personalizacion
     * @return array{clave: string, nombre: string, oscuro: bool, permite_personalizar: bool, tokens: array<string, string>, personalizacion: array<string, string>, fuente: array{clave: string, nombre: string}}
     */
    public static function resolver(?string $clave, ?array $personalizacion, ?string $fuente = null): array
    {
        $clave = $clave !== null && self::existe($clave) ? $clave : self::POR_DEFECTO;
        $tema = self::TEMAS[$clave];
        $propios = array_intersect_key($personalizacion ?? [], array_flip(self::PERSONALIZABLES));

        return [
            'clave' => $clave,
            'nombre' => $tema['nombre'],
            'oscuro' => $tema['oscuro'],
            // Todos los temas admiten ajustes personales (el único que no, «Alto
            // contraste», se retiró); el campo se conserva para quien lo lee.
            'permite_personalizar' => true,
            'tokens' => [...$tema['tokens'], ...$propios],
            'personalizacion' => $propios,
            'fuente' => CatalogoFuentes::resolver($fuente),
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
