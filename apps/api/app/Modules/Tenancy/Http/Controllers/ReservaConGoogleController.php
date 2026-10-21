<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\ReservaConGoogle;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Reserva con Google (ADR 0198): si el negocio aparece, qué le falta, encenderlo o
 * apagarlo y, sin sesión, avisar que una visita que llegó desde Google terminó en
 * reserva.
 */
class ReservaConGoogleController
{
    public function __construct(
        private readonly ReservaConGoogle $google,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function mostrar(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentar($this->estudio($request))]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $validado = $request->validate(['activo' => ['required', 'boolean']]);
        $estudio = $this->estudio($request);
        $activo = (bool) $validado['activo'];
        if ($activo && ! $this->google->configurada()) {
            throw ValidationException::withMessages(['activo' => ['Reserva con Google aún no está disponible en la plataforma.']]);
        }
        if ($activo && $this->google->faltantes($estudio) !== []) {
            throw ValidationException::withMessages(['activo' => ['Para aparecer, publica tu página y captura la dirección y la ubicación de al menos una sede.']]);
        }
        $estudio->forceFill(['en_reserva_google_desde' => $activo ? ($estudio->en_reserva_google_desde ?? now()) : null])->save();
        $usuario = $request->attributes->get('usuario_tenant');
        $this->auditoria->registrar($usuario instanceof Usuario ? $usuario : null, $activo ? 'google.reserva_activada' : 'google.reserva_desactivada', 'estudio', (string) $estudio->ulid);

        return response()->json(['data' => $this->presentar($estudio)]);
    }

    /** Sin sesión: la reserva que se hizo tras llegar desde Google (su `rwg_token`). */
    public function conversion(Request $request): JsonResponse
    {
        $validado = $request->validate(['rwg_token' => ['required', 'string', 'max:1000']]);
        $estudio = $this->estudio($request);
        $enviada = $estudio->en_reserva_google_desde !== null && $this->google->conversion((string) $validado['rwg_token']);

        return response()->json(['data' => ['enviada' => $enviada]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Estudio $estudio): array
    {
        return [
            'disponible' => $this->google->configurada(),
            'activo' => $estudio->en_reserva_google_desde !== null,
            'faltantes' => $this->google->faltantes($estudio),
        ];
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
