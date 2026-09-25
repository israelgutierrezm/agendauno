<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EliminacionesTenant;
use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\DestinatarioMensaje;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Models\PlantillaMensajeTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Plantillas de comunicacion del estudio (R28): asunto/cuerpo con marcadores {{...}}
 * que se disparan ante un evento (`clave`) por un `canal`. Idempotente por
 * (clave, canal). Opera SIEMPRE sobre la BD del estudio resuelto.
 */
class PlantillasMensajeTenantController
{
    public function index(): JsonResponse
    {
        $plantillas = PlantillaMensajeTenant::query()->orderBy('clave')->get();

        return response()->json([
            'data' => $plantillas->map(fn (PlantillaMensajeTenant $p): array => $this->presentar($p))->all(),
            'eventos_disponibles' => EventoDeDominioTenant::TIPOS,
            // Push solo aparece si la plataforma tiene FCM configurado.
            'canales' => array_map(static fn (CanalComunicacion $c): string => $c->value, CanalComunicacion::disponibles()),
        ]);
    }

    public function guardar(Request $request, EliminacionesTenant $eliminaciones): JsonResponse
    {
        $validado = $request->validate([
            'clave' => ['required', Rule::in(EventoDeDominioTenant::TIPOS)],
            'canal' => ['required', Rule::in(array_map(static fn (CanalComunicacion $c): string => $c->value, CanalComunicacion::disponibles()))],
            'asunto' => ['required', 'string', 'max:255'],
            'cuerpo' => ['required', 'string', 'max:5000'],
            'destinatario' => ['nullable', Rule::enum(DestinatarioMensaje::class)],
            'activo' => ['boolean'],
        ]);
        $para = DestinatarioMensaje::tryFrom((string) ($validado['destinatario'] ?? '')) ?? DestinatarioMensaje::Persona;
        if ($para === DestinatarioMensaje::Profesional && $validado['canal'] === CanalComunicacion::Interno->value) {
            throw ValidationException::withMessages(['canal' => ['Al profesional se le avisa por correo o notificación en la app.']]);
        }

        $plantilla = PlantillaMensajeTenant::withTrashed()->updateOrCreate(
            ['clave' => $validado['clave'], 'canal' => $validado['canal'], 'destinatario' => $para->value],
            [
                'asunto' => $validado['asunto'],
                'cuerpo' => $validado['cuerpo'],
                'activo' => (bool) ($validado['activo'] ?? true),
            ],
        );
        // Esa clave estaba eliminada: se restaura con los datos nuevos.
        if ($plantilla->trashed()) {
            $plantilla->restore();
            $eliminaciones->restaurado($plantilla, 'plantilla_mensaje', $plantilla->only($plantilla->getFillable()));
        }

        return response()->json(['data' => $this->presentar($plantilla)], 201);
    }

    public function eliminar(Request $request, EliminacionesTenant $eliminaciones): JsonResponse
    {
        $plantilla = PlantillaMensajeTenant::query()->where('ulid', (string) $request->route('plantilla'))->firstOrFail();
        // Baja lógica: deja de usarse; queda en la bitácora qué era y quién lo eliminó.
        $eliminaciones->eliminar($plantilla, 'plantilla_mensaje', $plantilla->only(['clave', 'canal', 'destinatario', 'asunto']));

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(PlantillaMensajeTenant $plantilla): array
    {
        return [
            'id' => $plantilla->ulid,
            'clave' => $plantilla->clave,
            'canal' => $plantilla->canal->value,
            'destinatario' => $plantilla->destinatario->value,
            'asunto' => $plantilla->asunto,
            'cuerpo' => $plantilla->cuerpo,
            'activo' => $plantilla->activo,
        ];
    }
}
