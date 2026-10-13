<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

/**
 * Lo que contestó Wellhub o TotalPass a una llamada de publicación o de respuesta a una
 * reserva (ADR 0149): hecho (con el id que dio, si dio uno), rechazado por la
 * plataforma (no sirve repetirlo igual) o sin respuesta (sin conexión, error de su lado,
 * demasiadas llamadas o credenciales que no aceptó: se reintenta después).
 */
final readonly class ResultadoPlataforma
{
    public function __construct(
        public bool $ok,
        public ?string $id = null,
        public ?string $error = null,
        public bool $reintentar = false,
        public ?string $evento = null,
    ) {}

    public static function hecho(?string $id = null, ?string $evento = null): self
    {
        return new self(true, $id, evento: $evento);
    }

    public static function rechazado(string $error): self
    {
        return new self(false, error: $error);
    }

    public static function sinRespuesta(string $error): self
    {
        return new self(false, error: $error, reintentar: true);
    }
}
