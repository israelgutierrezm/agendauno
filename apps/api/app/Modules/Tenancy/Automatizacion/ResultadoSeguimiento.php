<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Automatizacion;

/**
 * Con qué terminó un seguimiento (plan de mejoras M09): se mide qué pasó después sin
 * atribuirle causalidad automáticamente.
 */
enum ResultadoSeguimiento: string
{
    case Contactado = 'contactado';
    case Renovo = 'renovo';
    case NoRespondio = 'no_respondio';
    // Deja de recibir promociones (y contactos comerciales).
    case NoDeseaContacto = 'no_desea_contacto';
    case RequiereAtencion = 'requiere_atencion';
}
