<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * Lo que se pidió no procede con el presupuesto en su estado (ADR 0127): editar uno ya
 * respondido, aceptar uno vencido, convertir uno que no se aceptó… `meta.motivo` dice
 * cuál.
 */
class PresupuestoNoPermitido extends TenancyException
{
    public function __construct(string $mensaje, private readonly string $motivo)
    {
        parent::__construct($mensaje);
    }

    public function codigo(): string
    {
        return 'QUOTE_NOT_ALLOWED';
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return ['motivo' => $this->motivo];
    }
}
