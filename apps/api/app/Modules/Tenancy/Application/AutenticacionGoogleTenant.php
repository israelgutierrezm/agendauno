<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\AutenticacionGoogleInvalida;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Validation\ValidationException;

/**
 * Inicio de sesión con Google tenant-local, con el registro cerrado (ADR 0093).
 *
 * Google no crea cuentas ni las activa: solo entra quien ya tiene una cuenta activa
 * en el negocio Y conectó Google desde su perfil (`google_id`). Una invitación sin
 * activar se activa con su enlace, no con Google; un correo que coincide con una
 * cuenta que aún no conectó Google no entra (se le pide conectarlo desde su perfil).
 */
class AutenticacionGoogleTenant
{
    public function __construct(private readonly VerificadorGoogle $verificador) {}

    public function ejecutar(string $credential): Usuario
    {
        $identidad = $this->verificador->verificar($credential);

        if ($identidad === null) {
            throw new AutenticacionGoogleInvalida('No se pudo validar el acceso con Google.');
        }

        $usuario = Usuario::query()
            ->where('google_id', $identidad->googleId)
            ->where('activo', true)
            ->first();

        if (! $usuario instanceof Usuario) {
            $conCorreo = Usuario::query()->where('email', $identidad->email)->exists();

            throw new AutenticacionGoogleInvalida($conCorreo
                ? 'Primero entra con tu contraseña y conecta Google desde tu perfil.'
                : 'No hay una cuenta conectada a ese Google en este negocio. Pide al negocio que te dé acceso.');
        }

        return $usuario;
    }

    /**
     * Conecta la cuenta de Google al usuario que ya inició sesión. Una cuenta de
     * Google sirve para un solo usuario del negocio.
     */
    public function conectar(Usuario $usuario, string $credential): void
    {
        $identidad = $this->verificador->verificar($credential);

        if ($identidad === null) {
            throw new AutenticacionGoogleInvalida('No se pudo validar la cuenta de Google.');
        }

        $ocupada = Usuario::query()
            ->where('google_id', $identidad->googleId)
            ->whereKeyNot($usuario->getKey())
            ->exists();
        if ($ocupada) {
            throw ValidationException::withMessages([
                'credential' => ['Esa cuenta de Google ya está conectada a otro usuario de este negocio.'],
            ]);
        }

        $usuario->forceFill(['google_id' => $identidad->googleId])->save();
    }

    public function desconectar(Usuario $usuario): void
    {
        $usuario->forceFill(['google_id' => null])->save();
    }
}
