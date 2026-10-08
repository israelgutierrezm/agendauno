<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Models\Estudio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Niega las rutas de una función que no está en el plan del negocio (`plan:lealtad`,
 * ADR 0107). Solo aplica a los negocios de citas con plan por niveles; la web y la
 * app solo ocultan lo que aquí se niega. Corre junto a la de modalidad: con el
 * negocio resuelto, después de la sesión y del límite de peticiones.
 */
class FuncionDelPlan
{
    public function __construct(private readonly FuncionesPlan $funciones) {}

    public function handle(Request $request, Closure $next, string $funcion): Response
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        $this->funciones->exigir($estudio, $funcion);

        return $next($request);
    }
}
