<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use RuntimeException;

/**
 * Una fila de un archivo pasó la revisión, pero al guardarla ya no se pudo (otra operación
 * se le adelantó): deshace su lote (ADR 0230). No sale de los importadores.
 */
final class FilaYaNoSePuede extends RuntimeException
{
    public function __construct(public readonly int $fila, string $motivo)
    {
        parent::__construct($motivo);
    }
}
