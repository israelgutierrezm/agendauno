<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica al OPERADOR DE PLATAFORMA (PlatformAdmin) por un token dedicado (env
 * PLATFORM_ADMIN_TOKEN). No es una sesión de tenant: da acceso global a todos los
 * estudios y a la configuración de plataforma. Sin token configurado, el apartado
 * queda deshabilitado (401). Comparación en tiempo constante.
 */
class AutenticarPlataforma
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('agendauno.plataforma.token');
        $enviado = $request->bearerToken();

        abort_unless(
            is_string($token) && $token !== ''
                && is_string($enviado) && $enviado !== ''
                && hash_equals($token, $enviado),
            401,
            'No autorizado.',
        );

        return $next($request);
    }
}
