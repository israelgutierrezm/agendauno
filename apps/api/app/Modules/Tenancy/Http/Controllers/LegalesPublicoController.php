<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Documentos legales públicos de la plataforma (aviso de privacidad y términos),
 * los que edita el superadministrador y se muestran en el registro de negocios.
 * Sin autenticación (contenido público). Tolera que la tabla aún no exista.
 */
class LegalesPublicoController
{
    public function __invoke(): JsonResponse
    {
        try {
            $aviso = ConfiguracionPlataforma::obtener('aviso_privacidad');
            $terminos = ConfiguracionPlataforma::obtener('terminos');
        } catch (Throwable) {
            $aviso = null;
            $terminos = null;
        }

        return response()->json(['data' => [
            'aviso_privacidad' => $aviso,
            'terminos' => $terminos,
        ]]);
    }
}
