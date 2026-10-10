<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Producto comercial con el que se vende la plataforma (ADR 0108): una sola API, un
 * solo modelo de negocios y dos marcas, cada una con su dominio, su landing y su app.
 * - `AgendaUno` (agendauno.mx): negocios de clases.
 * - `TurnoUno` (turnouno.mx): negocios de citas.
 *
 * Hoy el producto sale de la modalidad del negocio (ADR 0104, excluyente): no se guarda
 * aparte y no puede contradecirla. Marca, dominio y URL de la web de cada producto
 * viven en `config/agendauno.php` (`productos`; AgendaUno, en `dominio_base` y
 * `url_app` de siempre), no en el código.
 */
enum ProductoComercial: string
{
    case AgendaUno = 'agendauno';
    case TurnoUno = 'turnouno';

    public static function deModalidad(ModalidadServicio $modalidad): self
    {
        return $modalidad === ModalidadServicio::Citas ? self::TurnoUno : self::AgendaUno;
    }

    /** La modalidad de los negocios de este producto. */
    public function modalidad(): ModalidadServicio
    {
        return $this === self::TurnoUno ? ModalidadServicio::Citas : ModalidadServicio::Clases;
    }

    /** El nombre de la marca («AgendaUno», «TurnoUno»). */
    public function nombre(): string
    {
        return (string) config("agendauno.productos.{$this->value}.nombre", $this->value);
    }

    /** Dominio de la marca, sin puntos sobrantes (`agendauno.mx`). */
    public function dominio(): string
    {
        $clave = $this === self::AgendaUno ? 'agendauno.dominio_base' : "agendauno.productos.{$this->value}.dominio";

        return strtolower(trim((string) config($clave), '.'));
    }

    /** URL de la web de la marca, sin diagonal final: base de los enlaces de correos y avisos. */
    public function urlWeb(): string
    {
        $clave = $this === self::AgendaUno ? 'agendauno.url_app' : "agendauno.productos.{$this->value}.url_web";

        return rtrim((string) config($clave), '/');
    }

    /**
     * El producto al que pertenece un host: su dominio o un subdominio de un nivel
     * (`agendauno.mx`, `estudio.agendauno.mx`). Null para cualquier otro (localhost, la
     * IP del servidor, un dominio ajeno, `a.b.agendauno.mx`).
     */
    public static function delHost(string $host): ?self
    {
        $host = strtolower(trim($host, '.'));
        foreach (self::cases() as $producto) {
            $dominio = $producto->dominio();
            if ($dominio !== '' && preg_match('/^([a-z0-9-]+\.)?'.preg_quote($dominio, '/').'$/', $host) === 1) {
                return $producto;
            }
        }

        return null;
    }
}
