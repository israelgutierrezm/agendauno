<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parámetros del negocio (ADR 0042): los límites y datos que el administrador puede
 * ajustar (tiempo para pagar, recordatorios, gracias de cobro…). Cada uno muestra el
 * valor de la plataforma, que aplica mientras el negocio no ponga el suyo.
 */
class ParametrosTenantController
{
    public function __construct(
        private readonly ParametrosTenant $parametros,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->parametros->delNegocioParaEditar()]);
    }

    /**
     * `valores`: {clave: número | null}; null vuelve al valor de la plataforma.
     */
    public function guardar(Request $request): JsonResponse
    {
        $validado = $request->validate(['valores' => ['required', 'array']]);
        $actor = $request->attributes->get('usuario_tenant');
        $actor = $actor instanceof Usuario ? $actor : null;

        $this->parametros->guardarDelNegocio($validado['valores'], $actor);
        $this->auditoria->registrar($actor, 'parametros.actualizados', 'parametros', null, null, $validado['valores']);

        return response()->json(['data' => $this->parametros->delNegocioParaEditar()]);
    }
}
