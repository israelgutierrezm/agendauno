<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\RolTenant;
use Illuminate\Support\Facades\Schema;

/**
 * Los roles del negocio: los de sistema (en el código) y los propios (en su base).
 * Resuelve permisos, faceta y orden de cualquiera de ellos. Guarda lo leído por
 * negocio mientras dura la petición o el trabajo (se registra `scoped`) y lo olvida
 * cuando cambian los roles propios.
 *
 * @phpstan-type Rol array{clave: string, nombre: string|null, faceta: string, permisos: list<string>, sistema: bool}
 */
class RolesTenant
{
    /** @var array<string, array<string, Rol>> roles por negocio */
    private array $leidos = [];

    public function __construct(private readonly GestorDeConexionTenant $gestor) {}

    /**
     * Todos los roles del negocio, del más amplio al más acotado: dueño, admin,
     * recepción, los propios del equipo (por nombre), instructor, los propios de
     * quien imparte (ADR 0078) y miembro.
     *
     * @return array<string, Rol>
     */
    public function todos(): array
    {
        $negocio = (string) ($this->gestor->actual()?->getKey() ?? '');
        if (isset($this->leidos[$negocio])) {
            return $this->leidos[$negocio];
        }

        $propios = [];
        // Fuera de un negocio (o antes de su migración) solo hay roles de sistema.
        if ($negocio !== '' && Schema::connection('tenant')->hasTable('roles')) {
            foreach (RolTenant::query()->orderBy('nombre')->get() as $rol) {
                $propios[$rol->clave] = [
                    'clave' => $rol->clave,
                    'nombre' => $rol->nombre,
                    'faceta' => $rol->faceta,
                    'permisos' => $rol->permisos,
                    'sistema' => false,
                ];
            }
        }

        $deEquipo = array_filter($propios, static fn (array $rol): bool => $rol['faceta'] !== 'instructor');
        $deInstructor = array_filter($propios, static fn (array $rol): bool => $rol['faceta'] === 'instructor');

        $roles = [];
        foreach (CatalogoDePermisosTenant::jerarquia() as $clave) {
            if ($clave === 'instructor') {
                $roles += $deEquipo;
            }
            if ($clave === 'miembro') {
                $roles += $deInstructor;
            }
            $roles[$clave] = [
                'clave' => $clave,
                'nombre' => null,
                'faceta' => CatalogoDePermisosTenant::faceta($clave),
                'permisos' => CatalogoDePermisosTenant::roles()[$clave],
                'sistema' => true,
            ];
        }

        return $this->leidos[$negocio] = $roles;
    }

    /**
     * Las claves de los roles de una faceta, de sistema y propios. Con `instructor`:
     * quienes imparten clases o atienden citas (ADR 0078).
     *
     * @return list<string>
     */
    public function clavesConFaceta(string $faceta): array
    {
        return array_keys(array_filter($this->todos(), static fn (array $rol): bool => $rol['faceta'] === $faceta));
    }

    /**
     * ¿Alguno de esos roles es de esa faceta?
     *
     * @param  list<string>  $claves
     */
    public function tieneFaceta(array $claves, string $faceta): bool
    {
        return array_intersect($claves, $this->clavesConFaceta($faceta)) !== [];
    }

    /** Tras crear, editar o borrar un rol propio. */
    public function olvidar(): void
    {
        $this->leidos = [];
    }

    public function existe(string $clave): bool
    {
        return isset($this->todos()[$clave]);
    }

    /**
     * Unión de permisos de un conjunto de roles; `['*']` si alguno lo da todo.
     *
     * @param  list<string>  $claves
     * @return list<string>
     */
    public function permisosDe(array $claves): array
    {
        $todos = $this->todos();
        $union = [];
        foreach ($claves as $clave) {
            $permisos = $todos[$clave]['permisos'] ?? [];
            if (in_array('*', $permisos, true)) {
                return ['*'];
            }
            $union = array_merge($union, $permisos);
        }

        return array_values(array_unique($union));
    }

    /**
     * @param  list<string>  $claves
     */
    public function puedeAlguno(array $claves, string $permiso): bool
    {
        $permisos = $this->permisosDe($claves);

        return in_array('*', $permisos, true) || in_array($permiso, $permisos, true);
    }

    /**
     * ¿Todos esos permisos están dentro de los que se tienen? (nadie da lo que no tiene)
     *
     * @param  list<string>  $permisos
     * @param  list<string>  $propios
     */
    public static function cabenEn(array $permisos, array $propios): bool
    {
        return in_array('*', $propios, true) || array_diff($permisos, $propios) === [];
    }

    /**
     * Los roles con los que puede entrar, en orden, con su faceta y su nombre (el de
     * los propios; los de sistema se nombran en cada app).
     *
     * @param  list<string>  $claves
     * @return list<array{clave: string, faceta: string, nombre: string|null}>
     */
    public function disponibles(array $claves): array
    {
        $disponibles = [];
        foreach ($this->todos() as $clave => $rol) {
            if (in_array($clave, $claves, true)) {
                $disponibles[] = ['clave' => $clave, 'faceta' => $rol['faceta'], 'nombre' => $rol['nombre']];
            }
        }

        return $disponibles;
    }

    /**
     * Rol principal: el de sistema más amplio; si solo tiene propios, el primero de
     * ellos; si no tiene ninguno conocido, miembro.
     *
     * @param  list<string>  $claves
     */
    public function principal(array $claves): string
    {
        return $this->disponibles($claves)[0]['clave'] ?? ($claves[0] ?? 'miembro');
    }
}
