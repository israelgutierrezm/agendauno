<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\SitioWeb;

use App\Modules\Tenancy\ModalidadServicio;

/**
 * Plantillas iniciales del sitio del negocio (ADR 0114): cada una propone un orden de
 * secciones y una forma de presentar la portada. El negocio parte de una y luego mueve,
 * oculta y edita lo que quiera.
 * - `Esencial`: portada con logo al centro; primero la agenda.
 * - `Portada`: la foto de portada a todo lo ancho con el nombre encima; primero lo que
 *   ofrece y su equipo.
 * - `Compacta`: encabezado corto y directo a reservar.
 */
enum PlantillaSitio: string
{
    case Esencial = 'esencial';
    case Portada = 'portada';
    case Compacta = 'compacta';

    /**
     * Las secciones que se mueven, en el orden de esta plantilla, solo las de la modalidad.
     *
     * @return list<string>
     */
    public function orden(ModalidadServicio $modalidad): array
    {
        $orden = match ($this) {
            self::Esencial => ['promociones', 'nosotros', 'agenda', 'servicios', 'horario', 'precios', 'equipo', 'resenas', 'sucursales'],
            self::Portada => ['promociones', 'servicios', 'equipo', 'nosotros', 'agenda', 'horario', 'precios', 'resenas', 'sucursales'],
            self::Compacta => ['agenda', 'promociones', 'servicios', 'horario', 'precios', 'sucursales', 'equipo', 'resenas', 'nosotros'],
        };
        $posibles = CatalogoSitioWeb::movibles($modalidad);

        return array_values(array_filter($orden, static fn (string $tipo): bool => in_array($tipo, $posibles, true)));
    }
}
