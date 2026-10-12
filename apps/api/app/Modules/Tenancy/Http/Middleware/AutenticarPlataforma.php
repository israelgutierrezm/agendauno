<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Platform\Acceso\AccesoPlataforma;
use App\Modules\Platform\Acceso\AdministradorPlataforma;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica al OPERADOR DE PLATAFORMA (PlatformAdmin). Da acceso global a todos los
 * estudios y a la configuración de plataforma; no es una sesión de tenant.
 *
 * Se entra con una sesión de operador (correo, contraseña, el token de la plataforma
 * y un código por correo; ADR 0126). El token `PLATFORM_ADMIN_TOKEN` por sí solo
 * sirve solo si `plataforma.token_directo` lo permite (fuera de producción, para
 * pruebas y scripts). Sin token configurado, el apartado queda deshabilitado (401).
 * Comparación en tiempo constante.
 */
class AutenticarPlataforma
{
    public function __construct(private readonly AccesoPlataforma $acceso) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = config('agendauno.plataforma.token');
        $enviado = $request->bearerToken();

        abort_unless(is_string($token) && $token !== '' && is_string($enviado) && $enviado !== '', 401, 'No autorizado.');

        $administrador = $this->acceso->autenticar($enviado);
        if ($administrador instanceof AdministradorPlataforma) {
            $request->attributes->set('administrador_plataforma', $administrador);
            // Para marcar «esta sesión» en el equipo, y quién fue en cada línea del log.
            $request->attributes->set('sesion_plataforma_huella', hash('sha256', $enviado));
            Log::withContext(['operador' => $administrador->email]);

            return $next($request);
        }

        abort_unless((bool) config('agendauno.plataforma.token_directo') && hash_equals($token, $enviado), 401, 'No autorizado.');

        return $next($request);
    }
}
