<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo que la API responde a quien trae credenciales (sesión del negocio, llave de API o
 * token de plataforma) es solo suyo: `Cache-Control: no-store, private`, para que ni un
 * proxy ni el navegador lo guarden y otro usuario del mismo equipo no lo vea (plan de
 * mejoras M11). Una respuesta que ya declara su propia caché (p. ej. un calendario
 * .ics con `max-age`) se respeta. Ninguna respuesta de la API es una página para
 * buscadores: `X-Robots-Tag: noindex`.
 */
class RespuestaPrivadaSinCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $conCredenciales = $request->headers->has('Authorization') || $request->headers->has('X-API-Key');
        if ($conCredenciales && ! $response->headers->hasCacheControlDirective('max-age')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        if (! $response->headers->has('X-Robots-Tag')) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
