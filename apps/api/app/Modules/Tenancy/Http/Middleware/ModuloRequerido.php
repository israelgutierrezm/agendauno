<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Exceptions\ModuloNoHabilitado;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\ModuloNegocio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Niega las rutas de un módulo que el superadministrador no encendió en el negocio
 * (`modulo:familias`, ADR 0123). Corre con el negocio resuelto, junto a la del plan.
 */
class ModuloRequerido
{
    public function handle(Request $request, Closure $next, string $modulo): Response
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        $requerido = ModuloNegocio::from($modulo);
        if (! $estudio->moduloHabilitado($requerido)) {
            throw new ModuloNoHabilitado($requerido);
        }

        return $next($request);
    }
}
