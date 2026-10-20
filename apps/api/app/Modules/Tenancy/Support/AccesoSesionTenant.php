<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Application\RolesTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;

/**
 * Politica de acceso a una sesion segun el usuario tenant-local. Un instructor solo
 * opera (ve roster, marca asistencia) sobre SUS sesiones asignadas; el resto del
 * staff (propietario/admin/recepcionista) opera sobre todas. Privacidad: un
 * instructor no debe ver los datos de las clases de otros. Ademas, el scope por
 * sucursal (R19) puede AMPLIAR el alcance de un instructor a toda una sucursal donde
 * tenga un rol asignado (p. ej. como recepcionista de esa sede).
 */
class AccesoSesionTenant
{
    public function __construct(
        private readonly ResolverAccesoTenant $acceso,
        private readonly RolesTenant $roles,
    ) {}

    public function puedeOperar(SesionTenant $sesion, ?Usuario $usuario): bool
    {
        return $this->puedeOperarEn((int) $sesion->sucursal_id, $sesion->instructor_id !== null ? (int) $sesion->instructor_id : null, $usuario);
    }

    /**
     * ¿Puede operar lo que se da en esa sucursal con ese profesional? Lo mismo que una
     * sesión que aún no existe: agendar una cita o pasarla a otro profesional. Quien
     * imparte agenda solo para sí (salvo un rol del equipo en esa sede).
     */
    public function puedeOperarEn(int $sucursalId, ?int $instructorId, ?Usuario $usuario): bool
    {
        if (! $usuario instanceof Usuario) {
            return false;
        }

        if (! $this->esInstructorAcotado($usuario)) {
            // El equipo acotado por sucursal (R19) solo opera las sesiones de sus sedes.
            return $this->acceso->permiteSucursal($usuario, $sucursalId);
        }

        if ($instructorId !== null && $instructorId === (int) $usuario->getKey()) {
            return true;
        }

        // Scope por sucursal (R19): un rol DEL EQUIPO asignado en la sucursal de la sesion
        // (p. ej. recepción de esa sede) amplía su alcance más allá de sus propias
        // sesiones. Estar asignado como quien imparte solo dice dónde trabaja (ADR 0098).
        $rol = $this->acceso->rolAsignado($usuario, $sucursalId);

        return $rol !== null
            && $this->roles->tieneFaceta([$rol], 'equipo')
            && $this->acceso->rolAsignadoPermite($usuario, 'asistencia.marcar', $sucursalId);
    }

    /**
     * ¿El usuario es instructor ACOTADO (alcance limitado a sus propias sesiones)?
     * Lo está si actúa con un rol de quien imparte (el de sistema o uno propio, ADR
     * 0078) sin un rol del equipo: en una sesión cuenta solo su rol activo.
     */
    public function esInstructorAcotado(?Usuario $usuario): bool
    {
        if (! $usuario instanceof Usuario) {
            return false;
        }

        $roles = $usuario->rolesVigentes();

        return $this->roles->tieneFaceta($roles, 'instructor') && ! $this->roles->tieneFaceta($roles, 'equipo');
    }
}
