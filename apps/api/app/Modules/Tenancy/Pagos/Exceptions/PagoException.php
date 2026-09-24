<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Pagos\Exceptions;

use RuntimeException;

/**
 * Base de los errores de dominio de pagos. Cada subclase expone un `code` estable
 * y su status HTTP; `ApiExceptionRenderer` los traduce al contrato de error.
 */
abstract class PagoException extends RuntimeException
{
    abstract public function codigo(): string;

    public function estadoHttp(): int
    {
        return 422;
    }
}
