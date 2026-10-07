<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * La modalidad del negocio ya no se puede cambiar (ADR 0104): su base ya tiene
 * sesiones o reservas, que son de la modalidad con que se crearon.
 */
class ModalidadEnUso extends TenancyException
{
    public function codigo(): string
    {
        return 'MODALITY_IN_USE';
    }
}
