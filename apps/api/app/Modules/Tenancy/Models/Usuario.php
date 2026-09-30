<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Application\RolesTenant;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
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
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $table = 'users';

    protected $fillable = [
        'name', 'email', 'password', 'google_id', 'activo', 'activation_token', 'rol', 'roles',
        'tema', 'tema_personalizacion', 'nombre', 'primer_apellido', 'segundo_apellido', 'foto_ruta',
        'ultimo_rol',
    ];

    /**
     * Rol con el que trabaja en esta sesión (lo fija la autenticación con el del
     * token). Fuera de una sesión (tareas programadas, avisos) queda en null y
     * cuentan todos sus roles.
     */
    private ?string $rolEnUso = null;

    /**
     * ¿Tiene el permiso? En una sesión, solo con su rol ACTIVO: quien administra y
     * además es alumno, al entrar como alumno no puede hacer lo del panel. Fuera de
     * una sesión, con cualquiera de sus roles.
     */
    public function puede(string $permiso): bool
    {
        return app(RolesTenant::class)->puedeAlguno($this->rolesVigentes(), $permiso);
    }

    /**
     * Fija el rol de la sesión: el pedido si todavía lo tiene; si no (o si no se
     * pidió ninguno), el de la última vez o su rol principal. Devuelve el que quedó.
     */
    public function usarRol(?string $rol): string
    {
        $this->rolEnUso = $rol !== null && in_array($rol, $this->rolesEfectivos(), true)
            ? $rol
            : $this->rolPorDefecto();

        return $this->rolEnUso;
    }

    /** El rol de la sesión, o null fuera de una sesión. */
    public function rolActivo(): ?string
    {
        return $this->rolEnUso;
    }

    /** Con el que entra si no elige: el de la última vez si aún lo tiene; si no, el principal. */
    public function rolPorDefecto(): string
    {
        $roles = $this->rolesEfectivos();

        return is_string($this->ultimo_rol) && in_array($this->ultimo_rol, $roles, true)
            ? $this->ultimo_rol
            : app(RolesTenant::class)->principal($roles);
    }

    /**
     * Roles que cuentan para permisos y alcance: el activo en una sesión; todos
     * fuera de ella. Para saber qué ES la persona (¿se le puede agendar?, ¿es
     * dueña?) se usa {@see rolesEfectivos()}.
     *
     * @return list<string>
     */
    public function rolesVigentes(): array
    {
        return $this->rolEnUso !== null ? [$this->rolEnUso] : $this->rolesEfectivos();
    }

    /** ¿Está actuando con este rol? (en una sesión, solo el activo cuenta). */
    public function actuaComo(string $rol): bool
    {
        return in_array($rol, $this->rolesVigentes(), true);
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
     * ¿Imparte clases o atiende citas? Tiene un rol de la faceta de quien imparte: el
     * de sistema o uno propio (ADR 0078). Se le agenda como profesional.
     */
    public function esProfesional(): bool
    {
        return app(RolesTenant::class)->tieneFaceta($this->rolesEfectivos(), 'instructor');
    }

    /**
     * Quienes imparten clases o atienden citas (ver {@see esProfesional()}).
     *
     * @param  Builder<self>  $consulta
     */
    public function scopeProfesionales(Builder $consulta): void
    {
        $claves = app(RolesTenant::class)->clavesConFaceta('instructor');
        $consulta->where(function (Builder $q) use ($claves): void {
            foreach ($claves as $clave) {
                $q->orWhereJsonContains('roles', $clave);
            }
        });
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
    protected $hidden = ['password', 'remember_token', 'activation_token', 'reset_token', 'email_nuevo_token', 'calendario_token', 'calendario_token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'reset_expira_en' => 'datetime',
            'email_nuevo_expira_en' => 'datetime',
            'calendario_token' => 'encrypted',
            'password' => 'hashed',
            'activo' => 'boolean',
            'roles' => 'array',
            'tema_personalizacion' => 'array',
        ];
    }
}
