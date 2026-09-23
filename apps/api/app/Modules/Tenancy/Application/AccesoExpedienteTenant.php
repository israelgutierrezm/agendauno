<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoPersonaTenant;

/**
 * Quién puede ver el expediente (documentos, formularios, consentimientos) de una
 * persona: el de un miembro, quien ve miembros; el de un instructor o del personal
 * (contratos, certificaciones…), solo quien administra al equipo.
 */
final class AccesoExpedienteTenant
{
    public static function puedeVer(Usuario $usuario, PersonaTenant $persona): bool
    {
        return $persona->tipo === TipoPersonaTenant::Miembro
            ? $usuario->puede('miembros.ver')
            : $usuario->puede('usuarios.gestionar');
    }
}
