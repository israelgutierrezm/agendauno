<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Cómo cobra AgendaUno (el SaaS) la suscripción a un estudio. Por defecto, `Activos`
 * (precio por alumno activo del periodo); el administrador de la plataforma puede
 * cambiar a `Fijo` (cuota mensual fija) desde el panel de tenants.
 */
enum ModoCobroSaas: string
{
    case Activos = 'activos';
    case Fijo = 'fijo';
}
