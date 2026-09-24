<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\BajaDePersonaTenant;
use App\Modules\Tenancy\Application\ExportarDatosPersonaTenant;
use App\Modules\Tenancy\Application\PersonaDeUsuarioTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SolicitudPrivacidadTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Privacidad del alumno (derechos ARCO frente al negocio): descargar sus datos
 * (acceso/portabilidad), oponerse a promociones y pedir la baja de sus datos
 * (cancelación). La rectificación está en "Mi perfil".
 */
class MiPrivacidadTenantController
{
    public function __construct(
        private readonly PersonaDeUsuarioTenant $personas,
        private readonly ExportarDatosPersonaTenant $exportar,
        private readonly BajaDePersonaTenant $baja,
    ) {}

    public function mostrar(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentar($this->persona($request))]);
    }

    public function actualizar(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        $validado = $request->validate(['recibe_promociones' => ['required', 'boolean']]);
        $persona->update(['recibe_promociones' => (bool) $validado['recibe_promociones']]);

        return response()->json(['data' => $this->presentar($persona)]);
    }

    public function datos(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        $nombre = 'mis-datos-'.Str::slug((string) $request->route('estudio')).'.json';

        return response()->json(['data' => $this->exportar->para($persona)], 200, [
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function solicitarBaja(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        $validado = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);
        $motivo = $validado['motivo'] ?? null;
        $this->baja->solicitar($persona, is_string($motivo) && trim($motivo) !== '' ? trim($motivo) : null);

        return response()->json(['data' => $this->presentar($persona)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(PersonaTenant $persona): array
    {
        $solicitud = SolicitudPrivacidadTenant::query()->where('persona_id', $persona->getKey())->latest('id')->first();

        return [
            'recibe_promociones' => (bool) ($persona->recibe_promociones ?? true),
            'baja' => $solicitud instanceof SolicitudPrivacidadTenant ? [
                'estado' => $solicitud->estado,
                'solicitada_en' => $solicitud->created_at?->toIso8601String(),
                'respuesta' => $solicitud->respuesta,
            ] : null,
        ];
    }

    private function persona(Request $request): PersonaTenant
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);
        $persona = $this->personas->buscar($usuario);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de alumno en este negocio.');

        return $persona;
    }
}
