<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Cómo cobra AgendaUno (el SaaS) la suscripción a un estudio. Por defecto, `Activos`:
 * POR USO según su modalidad (alumnos activos en clases, profesionales activos en
 * citas) con la tarifa vigente (ADR 0019). El administrador de la plataforma puede
 * pactar una cuota mensual `Fijo` desde el panel de tenants.
 */
enum ModoCobroSaas: string
{
    case Activos = 'activos';
    case Fijo = 'fijo';
}
