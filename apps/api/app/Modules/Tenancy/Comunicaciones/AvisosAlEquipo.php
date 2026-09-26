<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones;

use App\Modules\Tenancy\Application\CatalogoDePermisosTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Avisos al equipo del negocio: qué eventos pueden avisarse al equipo y a quién le
 * tocan. No se reparte por nombre de rol sino por el permiso con el que se atiende lo
 * que pasó (una solicitud de baja de datos le llega a quien puede atenderla).
 */
final class AvisosAlEquipo
{
    /**
     * Evento → permiso de quien debe enterarse.
     */
    private const PERMISO_POR_EVENTO = [
        'privacidad.baja_solicitada' => 'miembros.gestionar',
        'resena.creada' => 'miembros.gestionar',
        'cuenta.creada' => 'miembros.ver',
        'orden.pagada' => 'facturacion.ver',
        'cobro.fallido' => 'facturacion.ver',
        'membresia.suspendida' => 'facturacion.ver',
    ];

    /**
     * Los eventos que se pueden avisar al equipo.
     *
     * @return list<string>
     */
    public static function eventos(): array
    {
        return array_keys(self::PERMISO_POR_EVENTO);
    }

    /**
     * Los usuarios del equipo que deben enterarse del evento (activos en el negocio:
     * los dados de baja no cuentan).
     *
     * @return Collection<int, Usuario>
     */
    public static function destinatarios(string $evento): Collection
    {
        $permiso = self::PERMISO_POR_EVENTO[$evento] ?? null;
        if ($permiso === null) {
            return new Collection;
        }

        $roles = array_keys(array_filter(
            CatalogoDePermisosTenant::roles(),
            static fn (array $permisos): bool => in_array('*', $permisos, true) || in_array($permiso, $permisos, true),
        ));

        return Usuario::query()
            ->where(function (Builder $q) use ($roles): void {
                $q->whereIn('rol', $roles);
                foreach ($roles as $rol) {
                    $q->orWhereJsonContains('roles', $rol);
                }
            })
            ->orderBy('id')
            ->get()
            ->filter(static fn (Usuario $u): bool => $u->puede($permiso))
            ->values();
    }
}
