<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AlcancePersonaTenant;
use App\Modules\Tenancy\Application\BajaDeMembresiaTenant;
use App\Modules\Tenancy\Models\AcuerdoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

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
        $acuerdo = $this->acuerdo($request);
        $motivo = isset($validado['motivo']) ? trim((string) $validado['motivo']) : null;
        // Perdonar la mensualidad vencida es exentar una cuota: con `cuotas.exentar` y
        // con motivo, como exentar la penalización.
        $conservar = (bool) ($validado['conservar_adeudo'] ?? true);
        $perdona = ! $conservar && $this->bajas->simular($acuerdo)['adeudo_minor'] > 0;
        abort_if($perdona && ! ($actor?->puede('cuotas.exentar') ?? false), 403, 'No puedes perdonar la mensualidad pendiente.');
        if (($exentar || $perdona) && ($motivo === null || $motivo === '')) {
            throw ValidationException::withMessages(['motivo' => ['Escribe por qué se exenta.']]);
        }

        $baja = $this->bajas->ejecutar($acuerdo, $motivo, $exentar, $conservar, $actor);
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
        $acuerdo = AcuerdoTenant::query()->where('ulid', (string) $request->route('acuerdo'))->firstOrFail();
        app(AlcancePersonaTenant::class)->exigirAcuerdo($request, $acuerdo);

        return $acuerdo;
    }
}
