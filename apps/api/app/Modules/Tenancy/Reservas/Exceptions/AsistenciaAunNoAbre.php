<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * La asistencia de una clase o cita se registra desde unos minutos antes de que
 * empiece (`asistencia.minutos_antes`), no antes (ADR 0101).
 */
class AsistenciaAunNoAbre extends ReservaException
{
    public function codigo(): string
    {
        return 'ATTENDANCE_NOT_OPEN';
    }
}
