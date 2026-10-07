<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Exceptions\RestablecimientoInvalido;
use App\Modules\Tenancy\Mail\CorreoRestablecimiento;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Recuperación de contraseña tenant-local (dueño, equipo y alumnos). Opera sobre la
 * base del estudio resuelto. El token viaja solo en el correo; aquí se guarda su
 * hash, vence en una hora y sirve una vez. Al cambiarla se cierran todas las
 * sesiones abiertas de esa cuenta.
 */
class RestablecerContrasenaTenant
{
    public function __construct(
        private readonly EnviarActivacionTenant $activacion,
        private readonly AutenticacionTenant $auth,
        private readonly ParametrosTenant $parametros,
    ) {}

    /**
     * Envía el enlace si la cuenta existe. A una cuenta que aún no se activa le
     * reenvía su activación (es la forma de definir su primera contraseña).
     * No revela si el correo está registrado.
     */
    public function solicitar(Estudio $estudio, string $email): void
    {
        $usuario = Usuario::query()->where('email', $email)->first();
        if (! $usuario instanceof Usuario) {
            return;
        }

        if (! $usuario->activo) {
            $this->activacion->enviar($estudio, (string) $usuario->email);

            return;
        }

        $token = Str::random(48);
        $usuario->forceFill([
            'reset_token' => hash('sha256', $token),
            'reset_expira_en' => now()->addMinutes($this->parametros->entero('cuentas.minutos_restablecer_contrasena')),
        ])->save();

        Mail::to((string) $usuario->email)->queue(new CorreoRestablecimiento(
            (string) $estudio->nombre,
            (string) $estudio->slug,
            (string) $usuario->email,
            $token,
        ));
    }

    /**
     * Fija la contraseña nueva si el enlace es válido y vigente. El token y la
     * contraseña no aparecen en las trazas (#[\SensitiveParameter]).
     */
    public function restablecer(
        string $email,
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $password,
    ): Usuario {
        $usuario = Usuario::query()->where('email', $email)->first();

        if (! $usuario instanceof Usuario
            || ! $usuario->activo
            || $usuario->reset_token === null
            || $usuario->reset_expira_en === null
            || now()->greaterThan($usuario->reset_expira_en)
            || ! hash_equals((string) $usuario->reset_token, hash('sha256', $token))) {
            throw new RestablecimientoInvalido('El enlace para restablecer tu contraseña no es válido o ya venció. Pide uno nuevo.');
        }

        $usuario->forceFill([
            'password' => $password, // el cast `hashed` lo hashea una vez
            'reset_token' => null,
            'reset_expira_en' => null,
            'email_verified_at' => $usuario->email_verified_at ?? now(),
        ])->save();

        $this->auth->revocarTodos($usuario);

        return $usuario;
    }
}
