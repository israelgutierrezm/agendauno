<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoPersonaTenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quién puede ver el expediente (documentos, formularios, consentimientos) de una
 * persona: el de un miembro, quien ve miembros, de sus sedes (R19) y, si imparte,
 * solo de sus clientes ({@see AlcancePersonaTenant}); el de un instructor o del personal (contratos,
 * certificaciones…), solo quien además administra al equipo. Ver miembros se exige
 * siempre, como en la ruta del expediente: administrar al equipo sin ver miembros no
 * abre el expediente, así que tampoco sus respuestas (R02).
 *
 * Es la única regla para leerlo, también desde otras pantallas (R02): las respuestas de
 * una ficha se listan con {@see self::acotar()}, así que diseñar fichas no basta para
 * leer lo que contestó alguien cuyo expediente no se puede abrir.
 */
final class AccesoExpedienteTenant
{
    public static function puedeVer(Usuario $usuario, PersonaTenant $persona): bool
    {
        return $persona->tipo === TipoPersonaTenant::Miembro
            ? $usuario->puede('miembros.ver') && app(AlcancePersonaTenant::class)->puedeVer($usuario, $persona)
            : $usuario->puede('miembros.ver') && $usuario->puede('usuarios.gestionar');
    }

    /**
     * Acota una consulta de algo del expediente (`$columna` = la persona) a quienes el
     * usuario puede ver, con la misma regla que {@see self::puedeVer()}: listas,
     * búsquedas, páginas y totales salen ya filtrados.
     *
     * @param  Builder<*>  $consulta
     */
    public static function acotar(Builder $consulta, Usuario $usuario, string $columna = 'persona_id'): void
    {
        $miembros = $usuario->puede('miembros.ver');
        $equipo = $miembros && $usuario->puede('usuarios.gestionar');
        if (! $miembros) {
            $consulta->whereRaw('1 = 0');

            return;
        }

        $consulta->where(function (Builder $w) use ($usuario, $columna, $equipo): void {
            $visibles = PersonaTenant::withTrashed()->select('id')->where('tipo', TipoPersonaTenant::Miembro->value);
            app(AlcancePersonaTenant::class)->acotarPorPersona($visibles, $usuario, 'id');
            $w->orWhereIn($columna, $visibles);
            if ($equipo) {
                $w->orWhereIn($columna, PersonaTenant::withTrashed()->select('id')->where('tipo', '!=', TipoPersonaTenant::Miembro->value));
            }
        });
    }
}
