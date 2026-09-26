<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\PoliticaCancelacion;
use App\Modules\Tenancy\Models\ActividadTenant;
use App\Modules\Tenancy\Models\PoliticaCancelacionTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Politica de cancelacion/no-show del estudio (data plane del tenant, R8): una
 * politica global y, opcionalmente, overrides por actividad. La reserva congela la
 * politica vigente al crearse; esto solo administra la configuracion futura. Opera
 * SIEMPRE sobre la BD del estudio resuelto.
 */
class PoliticasCancelacionTenantController
{
    public function index(ParametrosTenant $parametros): JsonResponse
    {
        $politicas = PoliticaCancelacionTenant::query()->with('actividad')->orderBy('actividad_id')->get();
        // La que aplica mientras el negocio no defina la suya (la fija la plataforma).
        $defecto = PoliticaCancelacion::porDefecto($parametros);

        return response()->json([
            'data' => $politicas->map(fn (PoliticaCancelacionTenant $p): array => $this->presentar($p))->all(),
            'por_defecto' => [
                'horas_limite' => $defecto->horasLimite,
                'penaliza_tarde' => $defecto->penalizaTarde,
                'penaliza_no_show' => $defecto->penalizaNoShow,
                'tolerancia_no_show' => $defecto->toleranciaNoShow,
                'ventana_no_show_dias' => $defecto->ventanaNoShowDias,
            ],
        ]);
    }

    /**
     * Crea o actualiza la politica global (sin `actividad_id`) o el override de una
     * actividad. Idempotente por `actividad_id`.
     */
    public function guardar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'actividad_id' => ['nullable', 'string'],
            'horas_limite' => ['required', 'integer', 'min:0', 'max:720'],
            'penaliza_tarde' => ['required', 'boolean'],
            'penaliza_no_show' => ['required', 'boolean'],
            'tolerancia_no_show' => ['nullable', 'integer', 'min:0', 'max:100'],
            'ventana_no_show_dias' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $actividadId = null;
        if (($validado['actividad_id'] ?? '') !== '') {
            $actividad = ActividadTenant::query()->where('ulid', $validado['actividad_id'])->firstOrFail();
            $actividadId = (int) $actividad->getKey();
        }

        $politica = PoliticaCancelacionTenant::query()->updateOrCreate(
            ['actividad_id' => $actividadId],
            [
                'horas_limite' => (int) $validado['horas_limite'],
                'penaliza_tarde' => (bool) $validado['penaliza_tarde'],
                'penaliza_no_show' => (bool) $validado['penaliza_no_show'],
                'tolerancia_no_show' => (int) ($validado['tolerancia_no_show'] ?? 0),
                'ventana_no_show_dias' => isset($validado['ventana_no_show_dias']) ? (int) $validado['ventana_no_show_dias'] : null,
            ],
        );

        return response()->json(['data' => $this->presentar($politica->fresh(['actividad']))], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(PoliticaCancelacionTenant $politica): array
    {
        return [
            'id' => $politica->ulid,
            'actividad_id' => $politica->actividad?->ulid,
            'actividad' => $politica->actividad?->nombre,
            'horas_limite' => $politica->horas_limite,
            'penaliza_tarde' => $politica->penaliza_tarde,
            'penaliza_no_show' => $politica->penaliza_no_show,
            'tolerancia_no_show' => $politica->tolerancia_no_show,
            'ventana_no_show_dias' => $politica->ventana_no_show_dias,
        ];
    }
}
