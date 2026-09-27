<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ClimaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /clima: el clima del Inicio del equipo (instructor, recepción, dueño). Null si
 * no se pudo saber: el Inicio no depende de un servicio externo.
 */
class ClimaEquipoTenantController
{
    public function __invoke(Request $request, ClimaTenant $clima): JsonResponse
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 403);

        return response()->json(['data' => $clima->paraUsuario($usuario, $request->ip())]);
    }
}
