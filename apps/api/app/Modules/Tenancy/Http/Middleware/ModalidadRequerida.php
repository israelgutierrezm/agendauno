<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Exceptions\ModalidadNoDisponible;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\Estudio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Niega las rutas exclusivas de la otra modalidad (`modalidad:clases` o
 * `modalidad:citas`): un negocio es solo de clases o solo de citas (ADR 0104) y la
 * web y la app no son la frontera. Va solo en rutas exclusivas; las del núcleo
 * (sesiones, reservas, asistencia, catálogo, cobros…) no lo llevan.
 *
 * Corre después de resolver el negocio, autenticar y limitar peticiones, y antes de
 * resolver los modelos de la ruta (bootstrap/app.php): sin sesión responde 401 y
 * lo del otro modelo, 403 sin revelar si el recurso existe.
 */
class ModalidadRequerida
{
    public function handle(Request $request, Closure $next, string $modalidad): Response
    {
        $requerida = ModalidadServicio::from($modalidad);
        $estudio = $request->attributes->get('estudio');

        // Sin negocio resuelto la ruta está mal declarada: se niega, nunca se abre.
        abort_unless($estudio instanceof Estudio, 404);

        $delNegocio = $estudio->modalidad();
        if ($delNegocio !== $requerida) {
            throw new ModalidadNoDisponible($delNegocio);
        }

        return $next($request);
    }
}
