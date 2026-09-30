<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Operacion\ErroresPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Entrada pública para los errores de la web y la app (ADR 0080): sin sesión, porque
 * también fallan la página comercial y el registro. Tiene tope por IP y, en la
 * plataforma, un tope diario de errores nuevos. Siempre responde lo mismo, se haya
 * guardado o no.
 */
class ErroresClienteController
{
    public function __invoke(Request $request, ErroresPlataforma $errores): JsonResponse
    {
        /** @var array{origen: string, tipo?: string|null, mensaje: string, lugar?: string|null, traza?: string|null, ruta?: string|null, version?: string|null, estudio?: string|null} $datos */
        $datos = $request->validate([
            'origen' => ['required', 'string', Rule::in(['web', 'app'])],
            'tipo' => ['nullable', 'string', 'max:120'],
            'mensaje' => ['required', 'string', 'max:2000'],
            'lugar' => ['nullable', 'string', 'max:300'],
            'traza' => ['nullable', 'string', 'max:16000'],
            'ruta' => ['nullable', 'string', 'max:300'],
            'version' => ['nullable', 'string', 'max:40'],
            'estudio' => ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9-]+$/'],
        ]);

        $errores->desdeCliente([...$datos, 'navegador' => (string) $request->userAgent()]);

        return response()->json(['data' => ['recibido' => true]], 202);
    }
}
