<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\TerminologiaEstudio;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cómo se llaman las cosas en el negocio (ADR 0049): qué se reserva (clase, cita…),
 * quién lo toma (alumno, cliente…) y quién lo imparte (instructor, barbero…). Parte de
 * lo de su giro; el administrador elige otro de cada lista.
 */
class TerminologiaTenantController
{
    public function __construct(
        private readonly GestorDeConexionTenant $gestor,
        private readonly TerminologiaEstudio $terminologia,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->terminologia->paraEditar($this->estudio())]);
    }

    /**
     * `valores`: {sesion|miembro|instructor: opción | null}; null vuelve al del giro.
     */
    public function guardar(Request $request): JsonResponse
    {
        $validado = $request->validate(['valores' => ['required', 'array']]);
        $estudio = $this->estudio();
        $cambio = $this->terminologia->guardar($estudio, $validado['valores']);

        $actor = $request->attributes->get('usuario_tenant');
        $this->auditoria->registrar($actor instanceof Usuario ? $actor : null, 'terminologia.actualizada', 'estudio', (string) $estudio->slug, $cambio['antes'], $cambio['despues']);

        return response()->json(['data' => $this->terminologia->paraEditar($estudio->refresh())]);
    }

    private function estudio(): Estudio
    {
        $estudio = $this->gestor->actual();
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
