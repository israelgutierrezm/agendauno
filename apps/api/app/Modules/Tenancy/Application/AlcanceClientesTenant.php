<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Support\AccesoSesionTenant;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * A qué clientes llega quien imparte. Un instructor ACOTADO (con un rol de quien
 * imparte y sin un rol del equipo, {@see AccesoSesionTenant::esInstructorAcotado})
 * solo ve a quienes reservaron alguna de SUS sesiones: no el directorio completo
 * del negocio. El resto del personal ve a todos (con su alcance por sucursal).
 * Lo económico va aparte (`ordenes.ver`).
 */
class AlcanceClientesTenant
{
    public function __construct(private readonly AccesoSesionTenant $sesiones) {}

    public function esAcotado(?Usuario $usuario): bool
    {
        return $this->sesiones->esInstructorAcotado($usuario);
    }

    /**
     * Acota una consulta de personas a las suyas (sin efecto para el resto).
     *
     * @param  Builder  $consulta  sobre la tabla `personas`
     */
    public function acotar(Builder $consulta, ?Usuario $usuario): void
    {
        if (! $usuario instanceof Usuario || ! $this->esAcotado($usuario)) {
            return;
        }
        $consulta->whereExists(fn ($q) => $q->selectRaw('1')
            ->from('reservas')
            ->join('sesiones', 'sesiones.id', '=', 'reservas.sesion_id')
            ->whereColumn('reservas.persona_id', 'personas.id')
            ->where('sesiones.instructor_id', $usuario->getKey()));
    }

    public function puedeVer(PersonaTenant $persona, ?Usuario $usuario): bool
    {
        if (! $usuario instanceof Usuario || ! $this->esAcotado($usuario)) {
            return true;
        }

        return ReservaTenant::query()
            ->where('persona_id', $persona->getKey())
            ->whereHas('sesion', fn ($q) => $q->where('instructor_id', $usuario->getKey()))
            ->exists();
    }
}
