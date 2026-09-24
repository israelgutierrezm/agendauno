<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Inventario\Exceptions;

use RuntimeException;

/**
 * Base de los errores de dominio de inventario / POS (R21). Cada subclase expone un
 * `code` estable y su status HTTP; `ApiExceptionRenderer` los traduce al contrato de
 * error de la API.
 */
abstract class InventarioException extends RuntimeException
{
    abstract public function codigo(): string;

    public function estadoHttp(): int
    {
        return 422;
    }
}
