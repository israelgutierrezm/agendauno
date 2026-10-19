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
     * Bloques propios del negocio (ADR 0146): se agregan las veces que quiera, entre la
     * portada y el contacto, con su contenido y su estilo. No hay HTML ni código: cada
     * bloque tiene campos fijos.
     */
    public const BLOQUES = ['texto_imagen', 'galeria', 'preguntas', 'testimonios', 'llamado', 'video', 'beneficios', 'formulario'];

    /** Los bloques que llevan un texto y los que llevan una foto propia. */
    public const BLOQUES_CON_TEXTO = ['texto_imagen', 'llamado', 'video', 'formulario'];

    public const BLOQUES_CON_FOTO = ['texto_imagen', 'video'];

    /** Fondo y alineación de un bloque. */
    public const FONDOS = ['normal', 'suave', 'marca', 'oscuro'];

    public const ALINEACIONES = ['izquierda', 'centro'];

    /** Cuántas fotos, testimonios o beneficios caben en un bloque; cuántas preguntas. */
    public const MAX_ELEMENTOS = 12;

    /**
     * Cómo se muestra una sección o un bloque (ADR 0167, Plus): el equipo en tarjetas, en
     * mosaico o en carrusel; las reseñas, la galería y los testimonios en carrusel (la
     * galería también en mosaico). Null = como siempre.
     */
    public const PRESENTACIONES = [
        'equipo' => ['tarjetas', 'mosaico', 'carrusel'],
        'resenas' => ['carrusel'],
        'galeria' => ['mosaico', 'carrusel'],
        'testimonios' => ['carrusel'],
    ];

    /** La presentación si es una de su tipo; si no, null (como siempre). */
    public static function presentacion(string $tipo, mixed $valor): ?string
    {
        return is_string($valor) && in_array($valor, self::PRESENTACIONES[$tipo] ?? [], true) ? $valor : null;
    }

    public const MAX_PREGUNTAS = 20;

    /**
     * Páginas propias (ADR 0146, fase 2): su dirección (`/p/{direccion}`), su título y
     * su SEO; el texto del pie de página.
     */
    public const MAX_DIRECCION = 40;

    public const MAX_TITULO_PAGINA = 60;

    public const MAX_SEO_TITULO = 70;

    public const MAX_SEO_DESCRIPCION = 160;

    public const MAX_PIE = 300;

    /**
     * Estilo global del sitio (ADR 0146, fase 3): su tipografía (de una lista), sus
     * esquinas y si se ve claro, oscuro o como lo tenga quien lo visita. El color sale
     * de su marca si no elige otro.
     */
    public const TIPOGRAFIAS = ['predeterminada', 'moderna', 'clasica', 'elegante', 'amigable', 'sistema'];

    public const ESQUINAS = ['rectas', 'suaves', 'redondas'];

    public const TEMAS = ['automatico', 'claro', 'oscuro'];

    /** Las versiones publicadas a las que se puede volver (sus fotos se conservan). */
    public const VERSIONES = 10;

    public static function esBloque(string $tipo): bool
    {
        return in_array($tipo, self::BLOQUES, true);
    }

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
