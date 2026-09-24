<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Application\CatalogoDePermisosTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

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
 * @property string|null $nombre
 * @property string|null $primer_apellido
 * @property string|null $segundo_apellido
 * @property string|null $foto_ruta
 */
class Usuario extends Authenticatable
{
    use HasPublicId;
    use Notifiable;

    protected $connection = 'tenant';

    protected $table = 'users';

    protected $fillable = [
        'name', 'email', 'password', 'google_id', 'activo', 'activation_token', 'rol', 'roles',
        'tema', 'tema_personalizacion', 'nombre', 'primer_apellido', 'segundo_apellido', 'foto_ruta',
    ];

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
     * Primer nombre y apellido paterno ("María López"), para ubicar a alguien sin
     * mostrar su nombre completo. Con los campos del perfil es exacto; si solo hay
     * `name`, se toma el primer nombre y el penúltimo tramo (orden mexicano:
     * nombres, apellido paterno, apellido materno).
     */
    public function nombreCorto(): string
    {
        $nombre = trim((string) $this->nombre);
        if ($nombre !== '') {
            $primero = explode(' ', $nombre)[0];

            return trim($primero.' '.trim((string) $this->primer_apellido));
        }

        $partes = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $partes = array_values(array_filter($partes, static fn (string $p): bool => $p !== ''));

        return count($partes) >= 3
            ? $partes[0].' '.$partes[count($partes) - 2]
            : implode(' ', $partes);
    }

    /**
     * URL pública de la foto de perfil (o null si no tiene).
     */
    public function fotoUrl(): ?string
    {
        return $this->foto_ruta !== null && $this->foto_ruta !== ''
            ? Storage::disk('public')->url($this->foto_ruta)
            : null;
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
    protected $hidden = ['password', 'remember_token', 'activation_token', 'reset_token', 'email_nuevo_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'reset_expira_en' => 'datetime',
            'email_nuevo_expira_en' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
            'roles' => 'array',
            'tema_personalizacion' => 'array',
        ];
    }
}
