<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\AsignacionPersonalTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;

/**
 * Control de acceso tenant-local con SCOPE por sucursal (R19, ADR 0098). Un permiso lo
 * concede el rol tenant-wide del usuario (en cualquier sucursal) O, en su ausencia, el
 * rol que el usuario tenga asignado EN esa sucursal (aditivo). Opera sobre la BD del
 * tenant resuelto.
 *
 * Quién ve qué sucursales:
 * - propietario y administración: todas (nunca acotados);
 * - quien solo es cliente o alumno: no aplica (lo suyo va por su cuenta);
 * - el resto del personal: las que tiene asignadas. Sin ninguna, NO VE NADA. Con una
 *   sola sucursal en el negocio, todo es de ella (no hay a qué acotar); asignado a
 *   todas, ve todo (también lo que no tiene sucursal).
 */
class ResolverAccesoTenant
{
    /** @var array<int, list<int>> ids de las sucursales, por negocio (en esta solicitud) */
    private array $sucursalesDelNegocio = [];

    /**
     * ¿El usuario tiene el permiso EN esa sucursal por un rol ASIGNADO ahi (sin contar
     * su rol tenant-wide)? Util para ampliar el alcance de un rol que, tenant-wide,
     * esta acotado (p. ej. un instructor limitado a sus sesiones).
     */
    /**
     * El rol que el usuario tiene asignado EN esa sucursal (null si no tiene).
     */
    public function rolAsignado(Usuario $usuario, int $sucursalId): ?string
    {
        $rol = AsignacionPersonalTenant::query()
            ->where('usuario_id', $usuario->getKey())
            ->where('sucursal_id', $sucursalId)
            ->value('rol');

        return is_string($rol) ? $rol : null;
    }

    public function rolAsignadoPermite(Usuario $usuario, string $permiso, int $sucursalId): bool
    {
        $rol = AsignacionPersonalTenant::query()
            ->where('usuario_id', $usuario->getKey())
            ->where('sucursal_id', $sucursalId)
            ->value('rol');

        return is_string($rol) && CatalogoDePermisosTenant::puede($rol, $permiso);
    }

    /**
     * IDs de las sucursales donde el usuario tiene un rol asignado (scope explicito).
     *
     * @return list<int>
     */
    public function sucursalesAsignadas(Usuario $usuario): array
    {
        return AsignacionPersonalTenant::query()
            ->where('usuario_id', $usuario->getKey())
            ->pluck('sucursal_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * ¿Con estos roles se acota por sucursal? Todo el personal que no es propietario
     * ni administración (que gestionan todo el negocio); quien solo es cliente o
     * alumno, no.
     *
     * @param  list<string>  $roles
     */
    public function rolesAcotables(array $roles): bool
    {
        if (array_intersect(['propietario', 'admin'], $roles) !== []) {
            return false;
        }

        return array_diff($roles, ['miembro']) !== [];
    }

    /**
     * ¿El usuario (con el rol con que trabaja) se acota por sucursal?
     */
    public function esPersonalAcotable(Usuario $usuario): bool
    {
        return $this->rolesAcotables($usuario->rolesVigentes());
    }

    /**
     * ¿El usuario está ACOTADO por sucursal? (ve solo algunas, o ninguna).
     */
    public function esAcotadoPorSucursal(Usuario $usuario): bool
    {
        return $this->sucursalesPermitidas($usuario) !== null;
    }

    /**
     * Personal sin sucursal en un negocio con varias: no ve nada hasta que le asignen
     * una (la pantalla se lo dice).
     */
    public function sinSucursal(Usuario $usuario): bool
    {
        return $this->sucursalesPermitidas($usuario) === [];
    }

    /**
     * Sucursales (ids) a las que el usuario está ACOTADO, o `null` si ve TODAS. Úsalo
     * para filtrar consultas: `null` → sin filtro; lista → `whereIn('sucursal_id', ...)`
     * (una lista vacía no deja ver nada).
     *
     * @return list<int>|null
     */
    public function sucursalesPermitidas(Usuario $usuario): ?array
    {
        if (! $this->esPersonalAcotable($usuario)) {
            return null;
        }
        $todas = $this->sucursalesDelNegocio();
        // Con una sola sucursal (o ninguna), todo es de ella: no hay a qué acotar.
        if (count($todas) <= 1) {
            return null;
        }
        $asignadas = $this->sucursalesAsignadas($usuario);
        // Asignado a todas: ve todo (también lo que no tiene sucursal).
        if ($asignadas !== [] && array_diff($todas, $asignadas) === []) {
            return null;
        }

        return $asignadas;
    }

    /**
     * ¿El negocio tiene varias sucursales? (con una, la asignación no hace falta).
     */
    public function hayVariasSucursales(): bool
    {
        return count($this->sucursalesDelNegocio()) > 1;
    }

    /**
     * @return list<int>
     */
    private function sucursalesDelNegocio(): array
    {
        $negocio = (int) (app(GestorDeConexionTenant::class)->actual()?->getKey() ?? 0);

        return $this->sucursalesDelNegocio[$negocio] ??= SucursalTenant::query()
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Olvida las sucursales leídas (tras crear una en la misma solicitud).
     */
    public function olvidarSucursales(): void
    {
        $this->sucursalesDelNegocio = [];
    }

    /**
     * ¿El usuario puede ver/operar sobre la sucursal dada? Los NO acotados pueden con
     * cualquiera (incluida `null`); un acotado solo con las suyas (un recurso sin
     * sucursal, `null`, no pertenece a ninguna sede asignada → denegado).
     */
    public function permiteSucursal(Usuario $usuario, ?int $sucursalId): bool
    {
        $permitidas = $this->sucursalesPermitidas($usuario);
        if ($permitidas === null) {
            return true;
        }

        return $sucursalId !== null && in_array($sucursalId, $permitidas, true);
    }
}
