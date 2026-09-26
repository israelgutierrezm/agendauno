<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;

/**
 * Resultado de un registro de alumno: la cuenta ya creada (entra de una vez) o la
 * confirmación pendiente del correo (se le mandó el enlace).
 */
final class RegistroDeAlumno
{
    private function __construct(
        public readonly ?Usuario $usuario,
        public readonly ?PersonaTenant $persona,
        public readonly ?string $emailPorConfirmar,
        public readonly ?string $token,
    ) {}

    public static function creado(Usuario $usuario, PersonaTenant $persona): self
    {
        return new self($usuario, $persona, null, null);
    }

    public static function porConfirmar(string $email, string $token): self
    {
        return new self(null, null, $email, $token);
    }
}
