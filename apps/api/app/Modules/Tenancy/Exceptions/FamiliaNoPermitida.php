<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * El vínculo responsable → participante no se puede hacer así (B05). `meta.motivo`:
 * `desactivado` (el negocio no usa familias), `sin_cuenta` (el responsable no tiene
 * cuenta), `participante_invalido` (no es un alumno vigente o es el mismo responsable),
 * `ciclo` (ya es responsable de quien lo sería de él), `limite` (llegó al máximo de
 * participantes) o `estado` (el vínculo no está en un estado que lo permita).
 */
class FamiliaNoPermitida extends TenancyException
{
    public function __construct(string $mensaje, private readonly string $motivo)
    {
        parent::__construct($mensaje);
    }

    public function codigo(): string
    {
        return 'FAMILY_NOT_ALLOWED';
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return ['motivo' => $this->motivo];
    }
}
