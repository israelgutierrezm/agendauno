<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EliminacionesTenant;
use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\DestinatarioMensaje;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\PlantillasWhatsApp;
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
 *
 * WhatsApp (ADR 0069) solo existe para el negocio si la plataforma lo encendió: si
 * no, ni el canal ni sus avisos aparecen. Su texto es la plantilla fija aprobada por
 * Meta; el negocio solo la enciende o la apaga.
 */
class PlantillasMensajeTenantController
{
    public function index(): JsonResponse
    {
        $canales = CanalComunicacion::disponibles();
        $conWhatsApp = in_array(CanalComunicacion::WhatsApp, $canales, true);
        $plantillas = PlantillaMensajeTenant::query()
            ->when(! $conWhatsApp, fn ($q) => $q->where('canal', '!=', CanalComunicacion::WhatsApp->value))
            ->orderBy('clave')
            ->get();

        return response()->json([
            'data' => $plantillas->map(fn (PlantillaMensajeTenant $p): array => $this->presentar($p))->all(),
            'eventos_disponibles' => EventoDeDominioTenant::TIPOS,
            // Push solo aparece si la plataforma tiene FCM configurado; WhatsApp, si la
            // plataforma lo encendió.
            'canales' => array_map(static fn (CanalComunicacion $c): string => $c->value, $canales),
            // Qué avisos pueden ir por WhatsApp y su texto fijo.
            'whatsapp' => $conWhatsApp ? (object) collect(PlantillasWhatsApp::eventos())
                ->mapWithKeys(fn (string $evento): array => [$evento => PlantillasWhatsApp::para($evento)['texto'] ?? ''])
                ->all() : null,
            // Qué eventos se pueden avisar al profesional de la cita y al equipo.
            'destinatarios' => [
                'profesional' => DestinatarioMensaje::Profesional->eventos(),
                'equipo' => DestinatarioMensaje::Equipo->eventos(),
            ],
        ]);
    }

    public function guardar(Request $request, EliminacionesTenant $eliminaciones): JsonResponse
    {
        $validado = $request->validate([
            'clave' => ['required', Rule::in(EventoDeDominioTenant::TIPOS)],
            'canal' => ['required', Rule::in(array_map(static fn (CanalComunicacion $c): string => $c->value, CanalComunicacion::disponibles()))],
            'asunto' => ['required_unless:canal,whatsapp', 'nullable', 'string', 'max:255'],
            'cuerpo' => ['required_unless:canal,whatsapp', 'nullable', 'string', 'max:5000'],
            'destinatario' => ['nullable', Rule::enum(DestinatarioMensaje::class)],
            'activo' => ['boolean'],
        ]);
        $para = DestinatarioMensaje::tryFrom((string) ($validado['destinatario'] ?? '')) ?? DestinatarioMensaje::Persona;
        $eventos = $para->eventos();
        if ($eventos !== null && ! in_array($validado['clave'], $eventos, true)) {
            throw ValidationException::withMessages(['destinatario' => ['Ese aviso no se puede enviar a ese destinatario.']]);
        }
        if ($para !== DestinatarioMensaje::Persona && in_array($validado['canal'], [CanalComunicacion::Interno->value, CanalComunicacion::WhatsApp->value], true)) {
            throw ValidationException::withMessages(['canal' => ['Al equipo se le avisa por correo o notificación en la app.']]);
        }
        if ($validado['canal'] === CanalComunicacion::WhatsApp->value) {
            // Texto fijo: la plantilla aprobada por Meta para ese aviso.
            $whatsapp = PlantillasWhatsApp::para($validado['clave'])
                ?? throw ValidationException::withMessages(['clave' => ['Ese aviso no se puede mandar por WhatsApp.']]);
            $validado['asunto'] = $whatsapp['titulo'];
            $validado['cuerpo'] = $whatsapp['texto'];
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
            // WhatsApp: siempre el texto vigente de la plantilla de Meta.
            'cuerpo' => $plantilla->canal === CanalComunicacion::WhatsApp
                ? (PlantillasWhatsApp::para($plantilla->clave)['texto'] ?? $plantilla->cuerpo)
                : $plantilla->cuerpo,
            'activo' => $plantilla->activo,
        ];
    }
}
