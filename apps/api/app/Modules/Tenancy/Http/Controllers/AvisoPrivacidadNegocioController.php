<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El aviso de privacidad que el negocio publica para sus clientes (público, sin
 * sesión): lo consultan antes de dar sus datos, en su página o al agendar sin cuenta.
 * Siempre a la vista, aunque la página pública del negocio esté cerrada.
 */
class AvisoPrivacidadNegocioController
{
    public function __invoke(Request $request, WaiversTenant $waivers): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        $aviso = $waivers->avisoPrivacidad();
        abort_if($aviso === null, 404, 'Este negocio aún no publica su aviso de privacidad.');

        return response()->json(['data' => [
            'negocio' => $estudio->nombre,
            'titulo' => $aviso->titulo,
            'contenido' => $aviso->contenido,
            'version' => $aviso->version,
            'publicado_en' => $aviso->created_at?->toIso8601String(),
        ]]);
    }
}
