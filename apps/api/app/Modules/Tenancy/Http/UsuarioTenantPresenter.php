<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http;

use App\Modules\Tenancy\Application\CatalogoDePermisosTenant;
use App\Modules\Tenancy\CatalogoTemas;
use App\Modules\Tenancy\Models\Usuario;

/**
 * Representación JSON del usuario de la sesión (login, /yo y "Mi perfil"): quién
 * es, sus roles y permisos, su apariencia y su foto.
 */
class UsuarioTenantPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function datos(Usuario $usuario): array
    {
        $roles = $usuario->rolesEfectivos();

        return [
            'ulid' => $usuario->ulid,
            'nombre' => $usuario->name,
            // Partes del nombre (editables en "Mi perfil") y la forma corta para
            // ubicar a alguien: primer nombre + apellido paterno.
            'nombre_pila' => $usuario->nombre,
            'primer_apellido' => $usuario->primer_apellido,
            'segundo_apellido' => $usuario->segundo_apellido,
            'nombre_corto' => $usuario->nombreCorto(),
            'email' => $usuario->email,
            // Correo nuevo que espera confirmación por enlace (null si no hay o venció).
            'email_pendiente' => $usuario->email_nuevo_expira_en !== null && now()->lessThan($usuario->email_nuevo_expira_en)
                ? $usuario->email_nuevo
                : null,
            'foto_url' => $usuario->fotoUrl(),
            // ¿Ya tiene contraseña? (quien entra solo con Google aún no).
            'tiene_contrasena' => $usuario->password !== null && $usuario->password !== '',
            // El rol con el que trabaja en esta sesión, sus permisos y los roles con
            // los que puede entrar (quien tiene varios elige y cambia).
            'rol' => $usuario->rolActivo() ?? CatalogoDePermisosTenant::rolPrincipal($roles),
            'roles' => $roles,
            'roles_disponibles' => CatalogoDePermisosTenant::rolesDisponibles($roles),
            'permisos' => CatalogoDePermisosTenant::permisosDe($usuario->rolesVigentes()),
            // Tema y colores propios: el front los aplica al entrar (ver /apariencia).
            'apariencia' => CatalogoTemas::resolver($usuario->tema, $usuario->tema_personalizacion),
        ];
    }
}
