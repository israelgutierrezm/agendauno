<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * No se puede dar de baja (o reactivar) a esa persona o usuario: p. ej. darse de
 * baja a sí mismo, dejar al negocio sin dueño, o reactivar a quien pidió cancelar
 * sus datos (ARCO).
 */
class BajaNoPermitida extends TenancyException
{
    public function codigo(): string
    {
        return 'DEACTIVATION_NOT_ALLOWED';
    }
}
