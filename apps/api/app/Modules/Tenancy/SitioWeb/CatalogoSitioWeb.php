<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\SitioWeb;

use App\Modules\Tenancy\ModalidadServicio;

/**
 * Las secciones del sitio público de un negocio (ADR 0114). `inicio` (la portada) va
 * siempre primero y `contacto` (cómo escribirle y su aviso de privacidad) siempre al
 * final; las demás se ordenan y se ocultan. Lo que muestra cada una sale del sistema
 * (horarios, precios, servicios, equipo, sedes): el sitio solo decide si aparece, dónde
 * y con qué título y texto.
 */
final class CatalogoSitioWeb
{
    public const INICIO = 'inicio';

    public const CONTACTO = 'contacto';

    /** Las que llevan un texto propio del negocio. */
    public const CON_TEXTO = [self::INICIO, 'nosotros', self::CONTACTO];

    /** Las que llevan una foto propia del negocio. */
    public const CON_FOTO = ['nosotros'];

    /** Largo máximo de un título y de un texto. */
    public const MAX_TITULO = 80;

    public const MAX_TEXTO = 2000;

    /**
     * Las secciones que se mueven en un negocio de esta modalidad (ADR 0104): un
     * negocio de citas no tiene horario de clases.
     *
     * @return list<string>
     */
    public static function movibles(ModalidadServicio $modalidad): array
    {
        $todas = ['promociones', 'nosotros', 'agenda', 'servicios', 'horario', 'precios', 'equipo', 'resenas', 'sucursales'];

        return $modalidad === ModalidadServicio::Citas
            ? array_values(array_diff($todas, ['horario']))
            : $todas;
    }

    /**
     * Todas, con las fijas.
     *
     * @return list<string>
     */
    public static function tipos(ModalidadServicio $modalidad): array
    {
        return [self::INICIO, ...self::movibles($modalidad), self::CONTACTO];
    }
}
