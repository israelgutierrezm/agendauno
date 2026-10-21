<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AsistenteNegocioTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El asistente sobre los datos del negocio (ADR 0196): si se ofrece, preguntarle y
 * aplicar una acción que propuso (tras revisarla).
 */
class AsistenteTenantController
{
    public function __construct(
        private readonly AsistenteNegocioTenant $asistente,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function mostrar(Request $request): JsonResponse
    {
        return response()->json(['data' => ['disponible' => $this->asistente->disponible($this->estudio($request), $this->usuario($request))]]);
    }

    public function preguntar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'mensajes' => ['required', 'array', 'min:1', 'max:20'],
            'mensajes.*.rol' => ['required', 'in:usuario,asistente'],
            'mensajes.*.texto' => ['required', 'string', 'max:2000'],
        ]);
        /** @var list<array{rol: string, texto: string}> $mensajes */
        $mensajes = array_values($validado['mensajes']);

        return response()->json(['data' => $this->asistente->preguntar($request, $this->estudio($request), $this->usuario($request), $mensajes)]);
    }

    public function aplicar(Request $request): JsonResponse
    {
        $validado = $request->validate(['token' => ['required', 'string', 'max:6000']]);
        $usuario = $this->usuario($request);
        $hecho = $this->asistente->aplicar($this->estudio($request), $usuario, (string) $validado['token']);
        $this->auditoria->registrar($usuario, 'asistente.accion_aplicada', 'asistente', null, null, $hecho);

        return response()->json(['data' => $hecho]);
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }

    private function usuario(Request $request): Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);

        return $usuario;
    }
}
