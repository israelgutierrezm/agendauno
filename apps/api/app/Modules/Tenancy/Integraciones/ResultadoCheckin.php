<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Integraciones;

/**
 * Resultado de validar un codigo de check-in contra una plataforma de bienestar:
 * aprobado (`valido`), rechazado por el proveedor, o no verificable (`verificable`
 * en falso): sin conexión, error de su lado o credenciales que no aceptó. Uno no
 * verificable NUNCA se trata como aprobado (M10).
 */
final readonly class ResultadoCheckin
{
    public function __construct(
        public bool $valido,
        public string $referencia,
        public ?string $usuario = null,
        public ?string $motivo = null,
        public bool $verificable = true,
    ) {}

    public static function valido(string $referencia, ?string $usuario = null): self
    {
        return new self(true, $referencia, $usuario);
    }

    public static function invalido(string $motivo): self
    {
        return new self(false, '', null, $motivo);
    }

    /** El proveedor no pudo decir si la visita vale: ni aprobada ni rechazada. */
    public static function noVerificable(string $motivo, ?string $usuario = null): self
    {
        return new self(false, '', $usuario, $motivo, false);
    }
}
