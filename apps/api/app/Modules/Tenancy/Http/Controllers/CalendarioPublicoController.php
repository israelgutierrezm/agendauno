<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CalendarioPublicoTenant;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El calendario público de clases del negocio (plan de mejoras M02): las clases de un
 * periodo con sus lugares libres. Sin sesión, solo con la página pública abierta (como el
 * escaparate) y solo en negocios de clases.
 */
class CalendarioPublicoController
{
    public function __invoke(Request $request, CalendarioPublicoTenant $calendario): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio && $estudio->paginaPublica(), 404);

        $validado = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'dias' => ['nullable', 'integer', 'min:1', 'max:'.CalendarioPublicoTenant::MAX_DIAS],
            'sucursal' => ['nullable', 'string', 'max:40'],
            'actividad' => ['nullable', 'string', 'max:40'],
            'instructor' => ['nullable', 'string', 'max:40'],
        ]);

        return response()->json(['data' => $calendario->consultar(
            $validado['desde'] ?? null,
            (int) ($validado['dias'] ?? 7),
            [
                'sucursal' => $validado['sucursal'] ?? null,
                'actividad' => $validado['actividad'] ?? null,
                'instructor' => $validado['instructor'] ?? null,
            ],
        )]);
    }
}
