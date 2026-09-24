<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\BajaDePersonaTenant;
use App\Modules\Tenancy\Models\SolicitudPrivacidadTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Solicitudes de baja de datos (ARCO) que el negocio atiende o rechaza con motivo.
 * La ley pide responder en un plazo de 20 días hábiles.
 */
class SolicitudesPrivacidadTenantController
{
    public function __construct(private readonly BajaDePersonaTenant $baja) {}

    public function index(): JsonResponse
    {
        $solicitudes = SolicitudPrivacidadTenant::query()->with('persona')->orderByRaw("case when estado = 'pendiente' then 0 else 1 end")->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $solicitudes->map(fn (SolicitudPrivacidadTenant $s): array => $this->presentar($s))->all()]);
    }

    public function atender(Request $request): JsonResponse
    {
        $solicitud = $this->solicitud($request);

        return response()->json(['data' => $this->presentar($this->baja->atender($solicitud, $this->actor($request))->load('persona'))]);
    }

    public function rechazar(Request $request): JsonResponse
    {
        $solicitud = $this->solicitud($request);
        $validado = $request->validate(['respuesta' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->presentar($this->baja->rechazar($solicitud, (string) $validado['respuesta'], $this->actor($request))->load('persona'))]);
    }

    private function solicitud(Request $request): SolicitudPrivacidadTenant
    {
        return SolicitudPrivacidadTenant::query()->where('ulid', (string) $request->route('solicitud'))->with('persona')->firstOrFail();
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(SolicitudPrivacidadTenant $s): array
    {
        return [
            'id' => $s->ulid,
            'persona' => $s->persona?->nombreCompleto(),
            'persona_id' => $s->persona?->ulid,
            'tipo' => $s->tipo,
            'estado' => $s->estado,
            'motivo' => $s->motivo,
            'respuesta' => $s->respuesta,
            'solicitada_en' => $s->created_at?->toIso8601String(),
            'atendida_en' => $s->atendida_en?->toIso8601String(),
            // Plazo legal de respuesta: 20 días hábiles (aprox. 28 naturales).
            'vence_en' => $s->created_at?->copy()->addWeekdays(20)->toDateString(),
        ];
    }
}
