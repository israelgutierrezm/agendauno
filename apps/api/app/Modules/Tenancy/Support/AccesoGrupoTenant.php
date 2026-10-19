<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Application\RolesTenant;
use App\Modules\Tenancy\Models\GrupoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * A qué grupos llega quien hace la solicitud (R01 de la revisión del 2026-10-18). Un
 * grupo es de la sucursal de su plantilla:
 *
 * - quien no está acotado (propietario, administración, asignado a todas) ve todos;
 * - el equipo acotado por sucursal (R19, ADR 0098), solo los de sus sedes;
 * - quien imparte (sin rol del equipo), los que imparte él o los de una sede donde
 *   tiene un rol del equipo, como {@see AccesoSesionTenant}.
 *
 * El mismo filtro vale para listas, contadores y cada grupo por su ULID (lectura y
 * escritura): con el identificador de otra sede no se ve ni se opera nada.
 */
class AccesoGrupoTenant
{
    public function __construct(
        private readonly ResolverAccesoTenant $acceso,
        private readonly AccesoSesionTenant $sesiones,
        private readonly RolesTenant $roles,
    ) {}

    /**
     * @param  Builder<GrupoTenant>  $consulta
     * @return Builder<GrupoTenant>
     */
    public function acotar(Builder $consulta, Request $request): Builder
    {
        $actor = self::actor($request);
        if (! $actor instanceof Usuario) {
            return $consulta;
        }
        if ($this->sesiones->esInstructorAcotado($actor)) {
            $sedes = $this->sedesDelEquipo($actor);

            return $consulta->whereHas('plantilla', fn (Builder $p) => $p->where(fn (Builder $w) => $w
                ->where('instructor_id', $actor->getKey())
                ->when($sedes !== [], fn (Builder $q) => $q->orWhereIn('sucursal_id', $sedes))));
        }
        $sedes = $this->acceso->sucursalesPermitidas($actor);

        return $sedes === null
            ? $consulta
            : $consulta->whereHas('plantilla', fn (Builder $p) => $p->whereIn('sucursal_id', $sedes));
    }

    public function puedeVer(GrupoTenant $grupo, Request $request): bool
    {
        return $this->acotar(GrupoTenant::query()->whereKey($grupo->getKey()), $request)->exists();
    }

    public function exigir(GrupoTenant $grupo, Request $request): void
    {
        abort_unless($this->puedeVer($grupo, $request), 403, 'Este grupo es de una sucursal que no tienes asignada.');
    }

    /**
     * Las sedes donde quien imparte tiene además un rol del equipo (p. ej. recepción
     * de esa sede): ahí ve los grupos de todos.
     *
     * @return list<int>
     */
    private function sedesDelEquipo(Usuario $actor): array
    {
        return array_values(array_filter(
            $this->acceso->sucursalesAsignadas($actor),
            fn (int $sede): bool => ($rol = $this->acceso->rolAsignado($actor, $sede)) !== null
                && $this->roles->tieneFaceta([$rol], 'equipo'),
        ));
    }

    private static function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }
}
