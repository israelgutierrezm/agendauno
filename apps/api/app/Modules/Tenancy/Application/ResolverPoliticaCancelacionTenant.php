<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\PoliticaCancelacionTenant;
use App\Modules\Tenancy\Models\SesionTenant;

/**
 * Resuelve la politica de cancelacion/no-show que aplica a una sesion: primero el
 * override de su actividad (si existe), luego la politica global del estudio, y si no
 * hay ninguna, la {@see PoliticaCancelacion::porDefecto()}. Opera sobre la BD del
 * tenant resuelto.
 */
class ResolverPoliticaCancelacionTenant
{
    public function __construct(private readonly ParametrosTenant $parametros) {}

    public function paraSesion(SesionTenant $sesion): PoliticaCancelacion
    {
        $actividadId = $sesion->oferta?->actividad_id;

        $politica = null;
        if ($actividadId !== null) {
            $politica = PoliticaCancelacionTenant::query()->where('actividad_id', $actividadId)->first();
        }

        $politica ??= PoliticaCancelacionTenant::query()->whereNull('actividad_id')->first();

        return $politica instanceof PoliticaCancelacionTenant
            ? PoliticaCancelacion::deModelo($politica, $this->parametros)
            : PoliticaCancelacion::porDefecto($this->parametros);
    }
}
