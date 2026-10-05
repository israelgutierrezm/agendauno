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
        // Los colores de la página comercial (agendauno.mx): barra azul marino, lo
        // activo en el rosa de sus botones y el azul petróleo en las acciones.
        'agendauno_alternativo' => [
            'nombre' => 'Agenda Uno Alternativo',
            'oscuro' => false,
            'tokens' => [
                'barra' => '#182B39', 'barra_suave' => '#20384A', 'barra_texto' => '#B9C7CC',
                'barra_activo' => '#C43B80', 'barra_activo_texto' => '#FFFFFF',
                'barra_borde' => 'rgb(255 255 255 / 10%)', 'barra_titulo' => '#FFFFFF',
                'acento' => '#007E91', 'acento_texto' => '#FFFFFF',
                'fondo' => '#F6F8FC', 'superficie' => '#FFFFFF', 'superficie_2' => '#E9EDF5',
                'borde' => '#DCE1DE', 'texto' => '#182B39', 'texto_suave' => '#59666B',
            ],
        ],
        'oceano' => [
            'nombre' => 'Océano',
            'oscuro' => false,
            'tokens' => [
                'barra' => '#00344D', 'barra_suave' => '#00527C', 'barra_texto' => '#B8DCEC',
                'barra_activo' => '#0077B6', 'barra_activo_texto' => '#FFFFFF',
                'barra_borde' => 'rgb(255 255 255 / 10%)', 'barra_titulo' => '#FFFFFF',
                'acento' => '#006A89', 'acento_texto' => '#FFFFFF',
                'fondo' => '#F2F6F9', 'superficie' => '#FFFFFF', 'superficie_2' => '#E3EDF3',
                'borde' => '#DCE6EC', 'texto' => '#0F2233', 'texto_suave' => '#5A7382',
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
     * @param  array<string, string>|null  $personalizacion
     * @return array{clave: string, nombre: string, oscuro: bool, permite_personalizar: bool, tokens: array<string, string>, personalizacion: array<string, string>}
     */
    public static function resolver(?string $clave, ?array $personalizacion): array
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
