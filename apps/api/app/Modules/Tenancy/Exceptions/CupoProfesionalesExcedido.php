<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * El negocio de citas ya tiene todos los profesionales que contrató (ADR 0107): para
 * sumar otro, primero sube su plan.
 */
class CupoProfesionalesExcedido extends TenancyException
{
    public function __construct(private readonly int $contratados, private readonly int $actuales)
    {
        parent::__construct($contratados === 1
            ? 'Tu plan incluye un profesional. Para sumar otro, cambia tu plan en Mi suscripción.'
            : "Tu plan incluye {$contratados} profesionales. Para sumar otro, cambia tu plan en Mi suscripción.");
    }

    public function codigo(): string
    {
        return 'PROFESSIONAL_SEATS_EXCEEDED';
    }

    public function estadoHttp(): int
    {
        return 409;
    }

    public function meta(): array
    {
        return ['contratados' => $this->contratados, 'actuales' => $this->actuales];
    }
}
