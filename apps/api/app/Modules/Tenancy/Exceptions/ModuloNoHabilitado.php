<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Modules\Tenancy\ModuloNegocio;

/**
 * El módulo no está encendido en este negocio (ADR 0123): solo lo enciende el
 * superadministrador.
 */
class ModuloNoHabilitado extends TenancyException
{
    public function __construct(private readonly ModuloNegocio $modulo)
    {
        parent::__construct("{$modulo->etiqueta()} no está disponible en este negocio. Pídelo a soporte.");
    }

    public function codigo(): string
    {
        return 'MODULE_NOT_ENABLED';
    }

    public function estadoHttp(): int
    {
        return 403;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return ['modulo' => $this->modulo->value];
    }
}
