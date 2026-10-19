<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * Cambiar las preguntas de una ficha que ya tiene respuestas la publica como versión
 * nueva: quien ya respondió conserva su versión y lo que se pida después es lo nuevo.
 * Se pide confirmarlo (`confirmar_nueva_version`; ADR 0166).
 */
class NuevaVersionSinConfirmar extends TenancyException
{
    public function __construct(private readonly int $respuestas, private readonly int $version)
    {
        parent::__construct("Esta ficha ya tiene {$respuestas} respuesta(s): el cambio se publica como versión {$version}. Las respuestas anteriores conservan sus preguntas. Confírmalo para guardar.");
    }

    public function codigo(): string
    {
        return 'FORM_NEW_VERSION_UNCONFIRMED';
    }

    public function estadoHttp(): int
    {
        return 409;
    }

    public function meta(): array
    {
        return ['respuestas' => $this->respuestas, 'version' => $this->version];
    }
}
