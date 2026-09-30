<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\RolTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Crea, edita y borra los roles propios del negocio (ADR 0057). Nadie da más de lo
 * que tiene:
 * - un rol solo lleva permisos que quien lo arma tiene en su rol activo;
 * - no se edita ni se borra un rol con permisos que uno no tiene, ni uno que uno
 *   mismo tiene (evita quedarse fuera o subirse de nivel por la puerta de atrás);
 * - no se borra un rol que alguien tiene asignado.
 * Los roles de sistema no se tocan. Todo queda en la bitácora.
 *
 * Un rol propio es del equipo o de quien imparte (ADR 0078): el segundo agenda a la
 * persona como profesional y la acota a sus sesiones. La faceta no cambia después.
 */
class GestionarRolesTenant
{
    public function __construct(
        private readonly RolesTenant $roles,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    /** Las facetas que puede tener un rol propio. */
    public const FACETAS = ['equipo', 'instructor'];

    /**
     * Lo mínimo de un rol de quien imparte: su portal muestra sus clases o citas.
     */
    private const MINIMO_INSTRUCTOR = ['agenda.ver'];

    /**
     * @param  list<string>  $permisos
     */
    public function crear(Usuario $actor, string $nombre, array $permisos, string $faceta = 'equipo'): RolTenant
    {
        if (! in_array($faceta, self::FACETAS, true)) {
            throw ValidationException::withMessages(['faceta' => ['Un rol propio es del equipo o de quien imparte.']]);
        }
        $permisos = $this->validar($actor, $nombre, $permisos, null, $faceta);
        $rol = RolTenant::query()->create([
            'clave' => $this->claveNueva($nombre),
            'nombre' => trim($nombre),
            'faceta' => $faceta,
            'permisos' => $permisos,
        ]);
        $this->roles->olvidar();
        $this->auditoria->registrar($actor, 'rol.creado', 'rol', (string) $rol->ulid, null, $this->datos($rol));

        return $rol;
    }

    /**
     * @param  list<string>  $permisos
     */
    public function actualizar(Usuario $actor, RolTenant $rol, string $nombre, array $permisos): RolTenant
    {
        $this->exigirQuePuedaCambiarlo($actor, $rol);
        $permisos = $this->validar($actor, $nombre, $permisos, $rol, $rol->faceta);
        $antes = $this->datos($rol);
        $rol->update(['nombre' => trim($nombre), 'permisos' => $permisos]);
        $this->roles->olvidar();
        $this->auditoria->registrar($actor, 'rol.actualizado', 'rol', (string) $rol->ulid, $antes, $this->datos($rol));

        return $rol;
    }

    public function eliminar(Usuario $actor, RolTenant $rol): void
    {
        $this->exigirQuePuedaCambiarlo($actor, $rol);
        $personas = $this->personasCon($rol->clave);
        if ($personas > 0) {
            throw ValidationException::withMessages([
                'rol' => ["Lo tienen {$personas} persona(s): quítaselo antes de borrarlo."],
            ]);
        }
        $antes = $this->datos($rol);
        $rol->delete();
        $this->roles->olvidar();
        $this->auditoria->registrar($actor, 'rol.eliminado', 'rol', (string) $rol->ulid, $antes, null);
    }

    /** Cuántas personas tienen el rol (activas). */
    public function personasCon(string $clave): int
    {
        return Usuario::query()->whereJsonContains('roles', $clave)->count();
    }

    /** ¿Puede editarlo o borrarlo? (no es suyo y no pasa de lo que tiene) */
    public function puedeCambiarlo(Usuario $actor, RolTenant $rol): bool
    {
        return ! in_array($rol->clave, $actor->rolesEfectivos(), true)
            && RolesTenant::cabenEn($rol->permisos, $this->roles->permisosDe($actor->rolesVigentes()));
    }

    /**
     * @param  list<string>  $permisos
     * @return list<string>
     */
    private function validar(Usuario $actor, string $nombre, array $permisos, ?RolTenant $rol, string $faceta): array
    {
        $nombre = trim($nombre);
        $repetido = RolTenant::query()
            ->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])
            ->when($rol instanceof RolTenant, fn ($q) => $q->whereKeyNot($rol?->getKey()))
            ->exists();
        if ($repetido) {
            throw ValidationException::withMessages(['nombre' => ['Ya hay un rol con ese nombre.']]);
        }

        $permisos = array_values(array_unique($permisos));
        if ($permisos === []) {
            throw ValidationException::withMessages(['permisos' => ['Elige al menos un permiso.']]);
        }
        $desconocidos = array_diff($permisos, CatalogoDePermisosTenant::permisosDelCatalogo());
        if ($desconocidos !== []) {
            throw ValidationException::withMessages(['permisos' => ['Permiso desconocido: '.implode(', ', $desconocidos).'.']]);
        }
        // Cada permiso con lo que necesita para servir: sin eso, la pantalla que abre
        // queda a medias (se explica y se pide; no se agrega en silencio).
        $incompletos = CatalogoDePermisosTenant::requisitosFaltantes($permisos);
        if ($incompletos !== []) {
            throw ValidationException::withMessages([
                'permisos' => array_map(
                    fn (string $permiso, array $faltan): string => "«{$permiso}» necesita también: ".implode(', ', $faltan).'.',
                    array_keys($incompletos),
                    $incompletos,
                ),
            ]);
        }
        if ($faceta === 'instructor' && array_diff(self::MINIMO_INSTRUCTOR, $permisos) !== []) {
            throw ValidationException::withMessages([
                'permisos' => ['Quien imparte necesita ver la agenda para ver sus clases o citas.'],
            ]);
        }
        $propios = $this->roles->permisosDe($actor->rolesVigentes());
        if (! RolesTenant::cabenEn($permisos, $propios)) {
            throw ValidationException::withMessages([
                'permisos' => ['No puedes dar permisos que no tienes: '.implode(', ', array_diff($permisos, $propios)).'.'],
            ]);
        }

        return $permisos;
    }

    private function exigirQuePuedaCambiarlo(Usuario $actor, RolTenant $rol): void
    {
        if (in_array($rol->clave, $actor->rolesEfectivos(), true)) {
            throw ValidationException::withMessages(['rol' => ['No puedes cambiar ni borrar un rol que tú tienes.']]);
        }
        if (! RolesTenant::cabenEn($rol->permisos, $this->roles->permisosDe($actor->rolesVigentes()))) {
            throw ValidationException::withMessages(['rol' => ['Este rol tiene permisos que tú no tienes: no puedes cambiarlo.']]);
        }
    }

    /** Clave estable (`rol_…`) que no choca con la de otro rol ni con las de sistema. */
    private function claveNueva(string $nombre): string
    {
        $base = 'rol_'.(Str::limit(Str::slug($nombre, '_'), 30, '') ?: 'propio');
        $clave = $base;
        $n = 2;
        while (RolTenant::query()->where('clave', $clave)->exists() || $this->roles->existe($clave)) {
            $clave = $base.'_'.$n++;
        }

        return $clave;
    }

    /**
     * @return array{nombre: string, faceta: string, permisos: list<string>}
     */
    private function datos(RolTenant $rol): array
    {
        return ['nombre' => $rol->nombre, 'faceta' => $rol->faceta, 'permisos' => $rol->permisos];
    }
}
