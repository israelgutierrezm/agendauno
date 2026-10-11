<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\BajaDeMembresiaTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Dar de baja una membresía (plan de mejoras B04): primero la simulación (fecha
 * efectiva, permanencia, penalización, adeudo), luego la baja con lo mismo.
 */
class BajasMembresiaTenantController extends Controller
{
    public function __construct(private readonly BajaDeMembresiaTenant $bajas) {}

    public function simular(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->bajas->simular($this->acuerdo($request))]);
    }

    public function dar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'motivo' => ['nullable', 'string', 'max:300'],
            // Exentar la penalización: solo con `cuotas.exentar`.
            'exentar_penalizacion' => ['sometimes', 'boolean'],
            // La mensualidad vencida se cobra aparte salvo que el negocio la anule.
            'conservar_adeudo' => ['sometimes', 'boolean'],
        ]);
        $actor = $request->attributes->get('usuario_tenant');
        $actor = $actor instanceof Usuario ? $actor : null;
        $exentar = (bool) ($validado['exentar_penalizacion'] ?? false);
        abort_if($exentar && ! ($actor?->puede('cuotas.exentar') ?? false), 403, 'No puedes exentar la penalización.');

        $baja = $this->bajas->ejecutar(
            $this->acuerdo($request),
            isset($validado['motivo']) ? trim((string) $validado['motivo']) : null,
            $exentar,
            (bool) ($validado['conservar_adeudo'] ?? true),
            $actor,
        );
        $baja->loadMissing('acuerdo');

        return response()->json(['data' => [
            'id' => $baja->ulid,
            'efectiva_el' => $baja->efectiva_el->toDateString(),
            'penalizacion_minor' => $baja->penalizacion_exenta ? 0 : $baja->penalizacion_minor,
            'penalizacion_exenta' => $baja->penalizacion_exenta,
            'adeudo_minor' => $baja->adeudo_conservado ? $baja->adeudo_minor : 0,
            'estado_acuerdo' => $baja->acuerdo?->estado->value,
        ]], 201);
    }

    private function acuerdo(Request $request): AcuerdoTenant
    {
        return AcuerdoTenant::query()->where('ulid', (string) $request->route('acuerdo'))->firstOrFail();
    }
}
