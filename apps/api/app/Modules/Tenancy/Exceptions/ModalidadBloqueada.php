<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Modules\Tenancy\ModalidadServicio;

/**
 * El negocio eligió un giro de la otra modalidad (ADR 0104). Un negocio es solo de
 * clases o solo de citas; cambiar de una a otra lo hace la plataforma, no el negocio.
 */
class ModalidadBloqueada extends TenancyException
{
    public function __construct(private readonly ModalidadServicio $modalidad)
    {
        $otra = $modalidad === ModalidadServicio::Citas ? ModalidadServicio::Clases : ModalidadServicio::Citas;

        parent::__construct("Este negocio trabaja con {$modalidad->value}; cambiar a {$otra->value} lo hace AgendaUno.");
    }

    public function codigo(): string
    {
        return 'MODALITY_LOCKED';
    }

    public function meta(): array
    {
        return ['modalidad' => $this->modalidad->value];
    }
}
