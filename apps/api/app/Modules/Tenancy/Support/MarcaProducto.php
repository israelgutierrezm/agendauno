<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\ProductoComercial;

/**
 * La marca con que se le habla a la gente de un negocio (ADR 0108): el nombre del
 * producto en el pie de los correos, el remitente y la web a la que llevan sus
 * enlaces. Es la del producto del negocio (clases → AgendaUno, citas → TurnoUno); sin
 * negocio (la plataforma), AgendaUno.
 */
final class MarcaProducto
{
    public static function de(?Estudio $estudio): ProductoComercial
    {
        return $estudio?->producto() ?? ProductoComercial::AgendaUno;
    }

    /** La del negocio cuya base está conectada en esta petición o trabajo. */
    public static function actual(): ProductoComercial
    {
        return self::de(app(GestorDeConexionTenant::class)->actual());
    }

    /** La del negocio con ese slug (para un correo en cola que solo lleva el slug). */
    public static function deSlug(string $slug): ProductoComercial
    {
        return self::de(Estudio::query()->where('slug', $slug)->first());
    }

    /** Web del producto del negocio: base de los enlaces de sus correos y avisos. */
    public static function urlWeb(?Estudio $estudio): string
    {
        return self::de($estudio)->urlWeb();
    }

    /**
     * Base de la API para un enlace que se abre fuera de la web (el calendario iCal):
     * en producción la API se sirve en el dominio de cada producto, así que es el del
     * negocio; en desarrollo (APP_URL fuera de los dominios de los productos), APP_URL.
     */
    public static function urlApi(?Estudio $estudio): string
    {
        $app = rtrim((string) config('app.url'), '/');
        if (ProductoComercial::delHost((string) parse_url($app, PHP_URL_HOST)) === null) {
            return $app;
        }

        return 'https://'.self::de($estudio)->dominio();
    }

    /**
     * Dirección desde la que salen los correos del producto; sin una propia, la de la
     * plataforma (`MAIL_FROM_ADDRESS`).
     */
    public static function remitente(ProductoComercial $producto): string
    {
        $propio = trim((string) config("agendauno.productos.{$producto->value}.correo_remitente"));

        return $propio !== '' ? $propio : (string) config('mail.from.address');
    }
}
