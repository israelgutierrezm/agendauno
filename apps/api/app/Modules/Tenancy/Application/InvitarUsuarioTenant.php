<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\AsignacionPersonalTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Validation\ValidationException;

/**
 * Invita a alguien a tener cuenta en el negocio con un rol (ADR 0093: las cuentas
 * existen porque el negocio las crea): una cuenta inactiva y el correo para definir su
 * contraseña. Si el correo es de alguien dado de baja, vuelve su misma cuenta (con su
 * historial). Un profesional más debe caber en el plan del negocio (ADR 0107). Lo usan
 * el equipo (Usuarios) y la conversión de un prospecto en miembro.
 *
 * Quién puede dar el rol y si el plan incluye equipo lo revisa quien llama.
 */
class InvitarUsuarioTenant
{
    public function __construct(
        private readonly EnviarActivacionTenant $enviarActivacion,
        private readonly BajasTenant $bajas,
        private readonly RegistrarAuditoria $auditoria,
        private readonly RolesTenant $roles,
        private readonly CupoProfesionales $cupo,
    ) {}

    /**
     * @return array{usuario: Usuario, reactivado: bool, token: string}
     *
     * @throws ValidationException si el correo ya es de un usuario activo del negocio
     */
    public function invitar(Estudio $estudio, Usuario $actor, string $nombre, string $email, string $rol, ?SucursalTenant $sucursal = null): array
    {
        // Email único dentro de la BD del tenant (también el de alguien dado de baja:
        // ese correo es suyo y se reactiva su cuenta).
        $existente = Usuario::withTrashed()->where('email', $email)->first();
        if ($existente instanceof Usuario && ! $existente->trashed()) {
            throw ValidationException::withMessages(['email' => ['Ya existe un usuario con ese correo en este estudio.']]);
        }

        $datos = [
            'name' => $nombre,
            'email' => $email,
            'rol' => $rol,
            'roles' => [$rol],
            'activo' => false,
            'password' => null,
        ];
        $reactivado = $existente instanceof Usuario;
        $profesional = $this->roles->tieneFaceta([$rol], 'instructor');
        $usuario = $this->cupo->sumar($estudio, $profesional ? 1 : 0, function () use ($existente, $datos, $actor): Usuario {
            if ($existente instanceof Usuario) {
                // Vuelve: misma cuenta (su historial), con el rol de ahora y una invitación
                // nueva para definir su contraseña.
                $this->bajas->reactivarUsuario($existente, $actor, 'Invitado de nuevo al equipo.');
                $existente->forceFill($datos)->save();

                return $existente;
            }

            return Usuario::query()->create($datos);
        });

        // Asignación de sede (acota al usuario a esa sucursal con su rol).
        if ($sucursal instanceof SucursalTenant) {
            AsignacionPersonalTenant::query()->create([
                'usuario_id' => $usuario->getKey(),
                'sucursal_id' => $sucursal->getKey(),
                'rol' => $rol,
            ]);
        }

        if (! $reactivado) {
            $this->auditoria->registrar($actor, 'usuario.invitado', 'usuario', (string) $usuario->ulid, null, [
                'nombre' => $usuario->name,
                'email' => $usuario->email,
                'rol' => $rol,
            ]);
        }

        // Genera el token y ENVÍA la invitación por correo.
        $token = $this->enviarActivacion->enviar($estudio, (string) $usuario->email);

        return ['usuario' => $usuario, 'reactivado' => $reactivado, 'token' => $token];
    }
}
