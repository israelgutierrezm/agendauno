<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * Los negocios de citas (TurnoUno) no venden membresías ni planes que se renuevan:
 * venden bonos de sesiones (ADR 0130).
 */
class MembresiaNoDisponible extends TenancyException
{
    public function __construct(string $mensaje = 'Los negocios de citas venden bonos de sesiones, no membresías ni planes que se renuevan.')
    {
        parent::__construct($mensaje);
    }

    public function codigo(): string
    {
        return 'MEMBERSHIP_NOT_AVAILABLE';
    }
}
