<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * Se aceptó una versión de la autorización que ya no es la vigente (el negocio cambió el
 * texto mientras se leía): hay que leer la nueva antes de aceptarla (B05).
 */
class AutorizacionDesactualizada extends TenancyException
{
    public function __construct(private readonly int $version, private readonly string $texto)
    {
        parent::__construct('El texto de la autorización cambió. Léelo de nuevo antes de aceptarlo.');
    }

    public function codigo(): string
    {
        return 'AUTHORIZATION_TEXT_CHANGED';
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
        return ['version' => $this->version, 'texto' => $this->texto];
    }
}
