<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Comunicaciones\EstadoMensaje;
use App\Modules\Tenancy\Models\MensajeTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mensajes (comunicaciones) del estudio (R28): historial de lo generado/enviado, con
 * su estado. Filtrable por estado. Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class MensajesTenantController
{
    private const LIMITE = 200;

    public function index(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'estado' => ['nullable', Rule::enum(EstadoMensaje::class)],
        ]);

        $mensajes = MensajeTenant::query()
            ->when(
                isset($validado['estado']),
                fn ($q) => $q->where('estado', $validado['estado']),
            )
            ->with('persona')
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        return response()->json([
            'data' => $mensajes->map(fn (MensajeTenant $m): array => [
                'id' => $m->ulid,
                'persona' => $m->persona?->nombre,
                'canal' => $m->canal->value,
                'destinatario' => $m->destinatario,
                'asunto' => $m->asunto,
                'estado' => $m->estado->value,
                'intentos' => $m->intentos,
                'evento_ulid' => $m->evento_ulid,
                'enviado_en' => $m->enviado_en?->toIso8601String(),
            ])->all(),
        ]);
    }
}
