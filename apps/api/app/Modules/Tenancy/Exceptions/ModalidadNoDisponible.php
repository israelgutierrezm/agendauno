<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Modules\Tenancy\ModalidadServicio;

/**
 * La ruta es exclusiva de la otra modalidad: un negocio es solo de clases o solo de
 * citas (ADR 0104) y el servidor no deja usar lo del otro modelo. `meta.modalidad`
 * dice la del negocio.
 */
class ModalidadNoDisponible extends TenancyException
{
    public function __construct(private readonly ModalidadServicio $modalidadDelNegocio)
    {
        parent::__construct("Esto no está disponible en un negocio de {$modalidadDelNegocio->value}.");
    }

    public function codigo(): string
    {
        return 'MODALITY_NOT_AVAILABLE';
    }

    public function estadoHttp(): int
    {
        return 403;
    }

    /**
     * @return array{modalidad: string}
     */
    public function meta(): array
    {
        return ['modalidad' => $this->modalidadDelNegocio->value];
    }
}
