<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Los módulos que el superadministrador enciende o apaga en cada negocio (ADR 0123).
 * Apagados por omisión. Distinto de las funciones del plan (lo que se paga), de las
 * capacidades de la modalidad (clases o citas) y de la configuración del negocio: un
 * módulo apagado no existe para ese negocio (sus rutas responden 403
 * `MODULE_NOT_ENABLED` y la web y la app no lo muestran).
 */
enum ModuloNegocio: string
{
    // Un alumno con cuenta gestiona a personas a su cargo (B05).
    case Familias = 'familias';
    // La app en las tiendas con la marca del negocio (ADR 0188): alta y mensualidad.
    case AppConMarca = 'app_marca';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Familias => 'Familias y responsables',
            self::AppConMarca => 'App en tiendas con tu marca',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Familias => 'Un alumno con cuenta reserva, compra y ve los documentos de personas a su cargo (sus hijos, por ejemplo), con su autorización.',
            self::AppConMarca => 'Tu propia app en Google Play y App Store, con tu nombre, tu logo y tus colores. La app AgendaUno y tu app web siguen incluidas.',
        };
    }
}
