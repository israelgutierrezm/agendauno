<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

/**
 * Tipo de una sesión de la agenda tenant-local:
 * - `Clase`: abierta, con cupo; cualquiera con derecho puede reservarla.
 * - `Cita`: privada, materializada PARA una persona desde un hueco de disponibilidad.
 *   Solo la ven su titular y el staff; se libera al terminar su reserva.
 */
enum TipoSesionTenant: string
{
    case Clase = 'clase';
    case Cita = 'cita';
}
