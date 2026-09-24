<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\PausarMembresiaTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\PausaAcuerdoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pausar y reanudar una membresía o paquete desde la ficha del alumno
 * (`membresias.gestionar`). Opera sobre la BD del estudio resuelto.
 */
class PausasMembresiaTenantController
{
    public function __construct(private readonly PausarMembresiaTenant $pausas) {}

    public function pausar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'hasta' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);
        $acuerdo = $this->acuerdoDe($request);

        $motivo = $validado['motivo'] ?? null;
        $this->pausas->pausar(
            $acuerdo,
            CarbonImmutable::parse((string) $validado['hasta']),
            is_string($motivo) && trim($motivo) !== '' ? trim($motivo) : null,
            $this->actor($request),
        );

        return response()->json(['data' => $this->presentar($acuerdo)]);
    }

    public function reanudar(Request $request): JsonResponse
    {
        $acuerdo = $this->acuerdoDe($request);
        $this->pausas->reanudar($acuerdo, $this->actor($request));

        return response()->json(['data' => $this->presentar($acuerdo)]);
    }

    private function acuerdoDe(Request $request): AcuerdoTenant
    {
        return AcuerdoTenant::query()->where('ulid', (string) $request->route('acuerdo'))->firstOrFail();
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(AcuerdoTenant $acuerdo): array
    {
        $acuerdo->refresh()->load('pausaAbierta');
        $pausa = $acuerdo->pausaAbierta;

        return [
            'id' => $acuerdo->ulid,
            'estado' => $acuerdo->estado->value,
            'proxima_cobro_en' => $acuerdo->proxima_cobro_en?->toDateString(),
            'pausa' => $pausa instanceof PausaAcuerdoTenant ? [
                'desde' => $pausa->desde->toDateString(),
                'hasta' => $pausa->hasta->toDateString(),
                'motivo' => $pausa->motivo,
            ] : null,
        ];
    }
}
