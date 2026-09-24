<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CalendarioPersonalTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Calendario personal (iCal): el enlace privado de cada quien (Mi perfil) y el
 * calendario que leen Google Calendar, Apple u Outlook con ese enlace (sin sesión).
 */
class CalendarioTenantController
{
    public function __construct(private readonly CalendarioPersonalTenant $calendario) {}

    public function enlace(Request $request): JsonResponse
    {
        return $this->responder($this->calendario->enlace($this->usuario($request), $this->estudio($request)));
    }

    public function regenerar(Request $request): JsonResponse
    {
        return $this->responder($this->calendario->enlace($this->usuario($request), $this->estudio($request), nuevo: true));
    }

    public function feed(Request $request): Response
    {
        $usuario = $this->calendario->usuarioDe((string) $request->route('token'));
        abort_unless($usuario instanceof Usuario, 404);

        return response($this->calendario->ics($usuario, $this->estudio($request)), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="agendauno.ics"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }

    private function responder(string $url): JsonResponse
    {
        return response()->json(['data' => [
            'url' => $url,
            // Suscribirse con un toque (Apple Calendar / Outlook abren webcal://).
            'webcal' => (string) preg_replace('~^https?://~', 'webcal://', $url),
        ]]);
    }

    private function usuario(Request $request): Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);

        return $usuario;
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
