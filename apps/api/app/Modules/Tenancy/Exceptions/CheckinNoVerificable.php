<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * La plataforma de bienestar no pudo verificar la visita (sin conexión, error de su
 * lado o credenciales que no aceptó): no se registra como aprobada (M10). La visita
 * queda «sin verificar» (`meta.checkin`): se reintenta con el mismo código o quien
 * tiene permiso la registra como excepción.
 */
class CheckinNoVerificable extends TenancyException
{
    public function __construct(string $mensaje, private readonly string $checkin)
    {
        parent::__construct($mensaje);
    }

    public function codigo(): string
    {
        return 'CHECKIN_UNVERIFIABLE';
    }

    public function estadoHttp(): int
    {
        return 409;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return ['checkin' => $this->checkin];
    }
}
