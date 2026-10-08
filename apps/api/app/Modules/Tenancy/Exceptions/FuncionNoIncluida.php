<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * La función no está en el plan del negocio (ADR 0107): se desbloquea subiendo de nivel.
 */
class FuncionNoIncluida extends TenancyException
{
    public function __construct(private readonly string $funcion, private readonly string $nivel)
    {
        parent::__construct('Esta función está en el plan '.($nivel === 'pro' ? 'Pro' : 'Premium').'. Cámbialo en Mi suscripción.');
    }

    public function codigo(): string
    {
        return 'PLAN_FEATURE_NOT_INCLUDED';
    }

    public function estadoHttp(): int
    {
        return 403;
    }

    public function meta(): array
    {
        return ['funcion' => $this->funcion, 'nivel' => $this->nivel];
    }
}
