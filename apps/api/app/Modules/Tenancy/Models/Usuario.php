<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Application\CatalogoDePermisosTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Usuario tenant-local: identidad operativa que vive en la BD del propio tenant
 * (conexión `tenant`). El email es único por tenant, así que el mismo correo en
 * otro estudio es una cuenta distinta. Reemplaza, en el data plane, al `User`
 * global del esquema compartido (que queda solo durante la transición).
 *
 * Multi-rol: una misma persona puede tener varios roles a la vez (p. ej. miembro y
 * profesor). `roles` es la fuente de verdad; `rol` se conserva como rol PRINCIPAL
 * (el más privilegiado) para compatibilidad.
 *
 * @property string|null $rol
 * @property list<string>|null $roles
 * @property string|null $tema
 * @property array<string, string>|null $tema_personalizacion
 */
class Usuario extends Authenticatable
{
    use HasPublicId;
    use Notifiable;

    protected $connection = 'tenant';

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password', 'google_id', 'activo', 'activation_token', 'rol', 'roles', 'tema', 'tema_personalizacion'];

    /**
     * ¿El usuario tiene el permiso dado por CUALQUIERA de sus roles (unión)?
     */
    public function puede(string $permiso): bool
    {
        return CatalogoDePermisosTenant::puedeAlguno($this->rolesEfectivos(), $permiso);
    }

    /**
     * Roles vigentes del usuario. Usa `roles` (multi) y, si aún no está poblado,
     * cae al rol único `rol` (compatibilidad durante la transición).
     *
     * @return list<string>
     */
    public function rolesEfectivos(): array
    {
        $roles = $this->roles;
        if (is_array($roles)) {
            $limpios = array_values(array_filter($roles, static fn (string $r): bool => $r !== ''));
            if ($limpios !== []) {
                return $limpios;
            }
        }

        return $this->rol !== null && $this->rol !== '' ? [(string) $this->rol] : [];
    }

    /**
     * ¿El usuario tiene el rol dado entre sus roles vigentes?
     */
    public function tieneRol(string $rol): bool
    {
        return in_array($rol, $this->rolesEfectivos(), true);
    }

    /**
     * @var list<string>
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
            'roles' => 'array',
            'tema_personalizacion' => 'array',
        ];
    }
}
