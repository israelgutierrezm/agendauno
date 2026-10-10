<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Exceptions\ActivacionInvalida;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\MarcaProducto;
use Illuminate\Support\Str;

/**
 * Activación de la cuenta de un usuario tenant-local (propietario u otros): genera
 * un token de un solo uso (se guarda su hash en la BD del tenant) y, al activar,
 * fija la contraseña y marca la cuenta como activa. Sin contraseñas por defecto.
 */
class ActivacionPropietario
{
    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly PersonaDeUsuarioTenant $personas,
        private readonly RegistrarEventoTenant $eventos,
    ) {}

    /**
     * Genera (o refresca) el token de activación y devuelve el valor en claro
     * (para el enlace de activación; solo se muestra una vez).
     */
    public function generar(Estudio $estudio, ?string $email = null): string
    {
        $token = Str::random(48);
        $correo = $email ?? $estudio->contacto_email;

        $this->gestor->ejecutarEn($estudio, function () use ($correo, $token): void {
            $usuario = Usuario::query()->where('email', $correo)->firstOrFail();
            $usuario->forceFill(['activation_token' => hash('sha256', $token), 'activo' => false])->save();
        });

        return $token;
    }

    /**
     * Activa la cuenta: valida el token, fija la contraseña y marca activo. El token y
     * la contraseña no aparecen en las trazas (#[\SensitiveParameter]).
     */
    public function activar(
        Estudio $estudio,
        string $email,
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $password,
    ): Usuario {
        return $this->gestor->ejecutarEn($estudio, function () use ($estudio, $email, $token, $password): Usuario {
            $usuario = Usuario::query()->where('email', $email)->first();

            if ($usuario === null
                || $usuario->activation_token === null
                || ! hash_equals((string) $usuario->activation_token, hash('sha256', $token))) {
                throw new ActivacionInvalida('El enlace de activación no es válido.');
            }

            $usuario->forceFill([
                'password' => $password, // el cast `hashed` lo hashea una vez
                'activo' => true,
                'activation_token' => null,
                'email_verified_at' => now(),
            ])->save();

            // Con el registro cerrado (ADR 0093), el negocio da de alta la ficha y
            // después invita: al activar, la cuenta queda ligada a su ficha (así la
            // baja del cliente también le cierra la sesión).
            $persona = $this->personas->buscar($usuario);

            // Un cliente que activa su cuenta recibe la bienvenida (plantilla
            // `cuenta.creada`) con el enlace para entrar.
            if ($persona !== null && array_diff($usuario->rolesEfectivos(), ['miembro']) === []) {
                $this->eventos->registrar('cuenta.creada', 'persona', (string) $persona->ulid, [
                    'persona_id' => (string) $persona->ulid,
                    'enlace' => MarcaProducto::urlWeb($estudio).'/entrar?estudio='.rawurlencode((string) $estudio->slug),
                ]);
            }

            return $usuario;
        });
    }
}
