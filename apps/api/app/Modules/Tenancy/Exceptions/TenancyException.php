<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use RuntimeException;

/**
 * Base de los errores de dominio de tenancy/control plane. Cada subclase expone
 * un `code` estable y su status HTTP; `ApiExceptionRenderer` los traduce.
 */
abstract class TenancyException extends RuntimeException
{
    abstract public function codigo(): string;

    public function estadoHttp(): int
    {
        return 422;
    }

    /**
     * Datos para que el cliente resuelva el caso (el `meta` del error).
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [];
    }
}
