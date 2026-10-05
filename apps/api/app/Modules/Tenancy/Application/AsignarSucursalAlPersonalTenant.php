<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\AsignacionPersonalTenant;
use App\Modules\Tenancy\Models\Usuario;

/**
 * Asigna sucursales al personal (ADR 0098): sin sucursal, en un negocio con varias, no
 * vería nada. Se usa al abrir la segunda sucursal (el personal sin asignar se queda
 * con la primera, que era todo lo que veía) y al darle a alguien un rol de personal.
 */
class AsignarSucursalAlPersonalTenant
{
    public function __construct(
        private readonly ResolverAccesoTenant $acceso,
        private readonly RolesTenant $roles,
    ) {}

    /**
     * La asigna (o la restaura, si se le había quitado) con su rol en esa sucursal.
     */
    public function asignar(Usuario $usuario, int $sucursal, string $rol): void
    {
        // La clave (usuario, sucursal) es única también entre las quitadas.
        $asignacion = AsignacionPersonalTenant::withTrashed()->updateOrCreate(
            ['usuario_id' => $usuario->getKey(), 'sucursal_id' => $sucursal],
            ['rol' => $rol],
        );
        if ($asignacion->trashed()) {
            $asignacion->restore();
        }
    }

    /**
     * Al personal activo que no tiene ninguna sucursal, le asigna estas.
     *
     * @param  list<int>  $sucursales  ids de las sucursales que se le asignan
     * @return int a cuántas personas se les asignó
     */
    public function sinAsignar(array $sucursales): int
    {
        $asignadas = 0;
        Usuario::query()
            ->where('activo', true)
            ->whereNotIn('id', AsignacionPersonalTenant::query()->select('usuario_id'))
            ->each(function (Usuario $usuario) use ($sucursales, &$asignadas): void {
                $roles = $usuario->rolesEfectivos();
                if (! $this->acceso->rolesAcotables($roles)) {
                    return;
                }
                foreach ($sucursales as $sucursal) {
                    $this->asignar($usuario, $sucursal, $this->roles->principal($roles));
                }
                $asignadas++;
            });
        $this->acceso->olvidarSucursales();

        return $asignadas;
    }
}
