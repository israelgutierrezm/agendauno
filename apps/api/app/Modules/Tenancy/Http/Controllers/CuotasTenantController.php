<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CuotasTenant;
use App\Modules\Tenancy\Application\ExentarCuotaTenant;
use App\Modules\Tenancy\Models\ConfiguracionCuotasTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Las cuotas del negocio (plan de mejoras B04): cuánto cuestan la inscripción y la
 * anualidad, cuándo se cobran, y exentar una que está por cobrar.
 */
class CuotasTenantController extends Controller
{
    public function mostrar(): JsonResponse
    {
        return response()->json(['data' => $this->presentar(ConfiguracionCuotasTenant::actual())]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'inscripcion_minor' => ['required', 'integer', 'min:0', 'max:100000000'],
            'reingreso_meses' => ['required', 'integer', 'min:0', 'max:120'],
            'anualidad_minor' => ['required', 'integer', 'min:0', 'max:100000000'],
            'anualidad_modo' => ['required', Rule::in([ConfiguracionCuotasTenant::ANIVERSARIO, ConfiguracionCuotasTenant::FECHA_FIJA])],
            'anualidad_mes' => ['nullable', 'required_if:anualidad_modo,'.ConfiguracionCuotasTenant::FECHA_FIJA, 'integer', 'between:1,12'],
            'anualidad_dia' => ['nullable', 'required_if:anualidad_modo,'.ConfiguracionCuotasTenant::FECHA_FIJA, 'integer', 'between:1,31'],
        ]);
        $fija = $validado['anualidad_modo'] === ConfiguracionCuotasTenant::FECHA_FIJA;
        // Una fecha que existe en algún año (el 29 de febrero vale: en otros años, el 28).
        if ($fija && ! checkdate((int) $validado['anualidad_mes'], (int) $validado['anualidad_dia'], 2024)) {
            abort(422, 'Esa fecha no existe.');
        }

        $config = ConfiguracionCuotasTenant::query()->first() ?? new ConfiguracionCuotasTenant;
        $config->fill([
            ...$validado,
            'anualidad_mes' => $fija ? (int) $validado['anualidad_mes'] : null,
            'anualidad_dia' => $fija ? (int) $validado['anualidad_dia'] : null,
        ])->save();

        return response()->json(['data' => $this->presentar($config)]);
    }

    /**
     * Lo que se sumaría al venderle hoy un plan que cobra la inscripción (0 = nada):
     * para decirlo antes de cobrar.
     */
    public function dePersona(Request $request, CuotasTenant $cuotas): JsonResponse
    {
        $persona = PersonaTenant::query()->where('ulid', (string) $request->route('persona'))->firstOrFail();

        return response()->json(['data' => [
            'inscripcion_minor' => $cuotas->debeInscripcion($persona) ? ConfiguracionCuotasTenant::actual()->inscripcion_minor : 0,
        ]]);
    }

    /**
     * Exentar una cuota por cobrar (inscripción, anualidad o penalización): sale de la
     * orden y queda en la bitácora con quién y por qué.
     */
    public function exentar(Request $request, ExentarCuotaTenant $exentar): JsonResponse
    {
        $validado = $request->validate(['motivo' => ['required', 'string', 'max:300']]);
        $orden = OrdenTenant::query()->where('ulid', (string) $request->route('orden'))->firstOrFail();
        $linea = LineaOrdenTenant::query()
            ->where('orden_id', $orden->getKey())
            ->where('ulid', (string) $request->route('linea'))
            ->firstOrFail();
        $actor = $request->attributes->get('usuario_tenant');

        $orden = $exentar->ejecutar($linea, trim((string) $validado['motivo']), $actor instanceof Usuario ? $actor : null);

        return response()->json(['data' => [
            'id' => $orden->ulid,
            'estado' => $orden->estado->value,
            'total_minor' => $orden->total_minor,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(ConfiguracionCuotasTenant $config): array
    {
        return [
            'inscripcion_minor' => (int) $config->inscripcion_minor,
            'reingreso_meses' => (int) $config->reingreso_meses,
            'anualidad_minor' => (int) $config->anualidad_minor,
            'anualidad_modo' => $config->anualidad_modo,
            'anualidad_mes' => $config->anualidad_mes,
            'anualidad_dia' => $config->anualidad_dia,
        ];
    }
}
