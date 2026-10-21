<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas\Exceptions;

/**
 * El plan no trae pases de invitado, ya se usaron los del mes o ya tiene un invitado en
 * esa clase (ADR 0182).
 */
class PaseDeInvitadoNoDisponible extends ReservaException
{
    public function codigo(): string
    {
        return 'GUEST_PASS_NOT_AVAILABLE';
    }
}
