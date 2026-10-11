<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Ordenes\Exceptions;

/**
 * Solo se exenta una cuota (inscripción, anualidad, penalización; B04) que está por
 * cobrar y sin un pago en línea en curso.
 */
class CuotaNoExentable extends OrdenException
{
    public function codigo(): string
    {
        return 'FEE_NOT_EXEMPTABLE';
    }
}
