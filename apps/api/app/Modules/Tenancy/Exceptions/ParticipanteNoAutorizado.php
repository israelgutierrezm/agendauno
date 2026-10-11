<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * La cuenta pidió actuar por un participante (`X-Participante`) que no está a su cargo,
 * cuya autorización está pendiente o se retiró, o el negocio ya no usa familias (B05).
 */
class ParticipanteNoAutorizado extends TenancyException
{
    public function codigo(): string
    {
        return 'GUARDIAN_NOT_AUTHORIZED';
    }

    public function estadoHttp(): int
    {
        return 403;
    }
}
