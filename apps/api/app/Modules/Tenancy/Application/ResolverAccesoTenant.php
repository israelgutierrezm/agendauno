<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\AsignacionPersonalTenant;
use App\Modules\Tenancy\Models\Usuario;

/**
 * Control de acceso tenant-local con SCOPE por sucursal (R19). Un permiso lo concede el rol
 * tenant-wide del usuario (en cualquier sucursal) O, en su ausencia, el rol que el
 * usuario tenga asignado EN esa sucursal (aditivo). Opera sobre la BD del tenant
 * resuelto.
 */
class ResolverAccesoTenant
{
    /**
     * ¿El usuario tiene el permiso EN esa sucursal por un rol ASIGNADO ahi (sin contar
     * su rol tenant-wide)? Util para ampliar el alcance de un rol que, tenant-wide,
     * esta acotado (p. ej. un instructor limitado a sus sesiones).
     */
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
     * ¿El usuario está ACOTADO por sucursal? Lo está cuando NO es propietario/admin
     * (roles que gestionan todo el estudio) y tiene asignaciones de sucursal explícitas.
     * Sin asignaciones = NO acotado: ve todo (compatible con estudios de una sola
     * sucursal y con el staff ya existente que no se ha asignado a ninguna sede).
     */
    public function esAcotadoPorSucursal(Usuario $usuario): bool
    {
        if (array_intersect(['propietario', 'admin'], $usuario->rolesVigentes()) !== []) {
            return false;
        }

        return $this->sucursalesAsignadas($usuario) !== [];
    }

    /**
     * Sucursales (ids) a las que el usuario está ACOTADO, o `null` si ve TODAS. Úsalo
     * para filtrar consultas: `null` → sin filtro; lista → `whereIn('sucursal_id', ...)`.
     *
     * @return list<int>|null
     */
    public function sucursalesPermitidas(Usuario $usuario): ?array
    {
        return $this->esAcotadoPorSucursal($usuario)
            ? $this->sucursalesAsignadas($usuario)
            : null;
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
