<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\TarifaSaas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tarifas del SaaS por modalidad (superadmin, ADR 0019). Las tarifas son VERSIONADAS:
 * publicar cambios crea una versión nueva (nunca se edita una publicada) y cada cargo
 * guarda la versión con que se calculó. Dinero en minor, sin IVA.
 */
class TarifasPlataformaController
{
    public function index(): JsonResponse
    {
        $data = [];
        foreach (ModalidadServicio::cases() as $modalidad) {
            $vigente = TarifaSaas::vigente($modalidad);
            $data[$modalidad->value] = [
                'vigente' => $vigente instanceof TarifaSaas ? $this->presentar($vigente) : null,
                'historial' => TarifaSaas::query()
                    ->where('modalidad', $modalidad->value)
                    ->orderByDesc('version')
                    ->limit(10)
                    ->get()
                    ->map(fn (TarifaSaas $t): array => $this->presentar($t))
                    ->all(),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Publica una versión nueva de la tarifa de una modalidad (vigente desde ahora o
     * desde la fecha indicada).
     */
    public function publicar(Request $request, string $modalidad): JsonResponse
    {
        $modo = ModalidadServicio::tryFrom($modalidad);
        abort_if($modo === null, 404);

        $comunes = [
            'dias_prueba' => ['required', 'integer', 'min:0', 'max:365'],
            'iva_porcentaje' => ['required', 'integer', 'min:0', 'max:50'],
            'vigente_desde' => ['nullable', 'date', 'after_or_equal:today'],
        ];
        $reglas = $modo === ModalidadServicio::Clases
            ? $comunes + [
                'bandas' => ['required', 'array', 'min:1', 'max:30'],
                'bandas.*.hasta' => ['nullable', 'integer', 'min:1'],
                'bandas.*.monto_minor' => ['required', 'integer', 'min:0', 'max:100000000'],
            ]
            : $comunes + [
                'tramos' => ['required', 'array', 'min:1', 'max:30'],
                'tramos.*.hasta' => ['nullable', 'integer', 'min:1'],
                'tramos.*.unitario_minor' => ['required', 'integer', 'min:0', 'max:100000000'],
                'personas_incluidas_por_profesional' => ['required', 'integer', 'min:0', 'max:10000'],
                'tope_personas_incluidas' => ['required', 'integer', 'min:0', 'max:1000000'],
                'extra_por_persona_minor' => ['required', 'integer', 'min:0', 'max:100000000'],
                'horas_medio_tiempo' => ['required', 'integer', 'min:1', 'max:80'],
            ];
        $validado = $request->validate($reglas);

        $campo = $modo === ModalidadServicio::Clases ? 'bandas' : 'tramos';
        $escalones = $this->escalones($campo, $validado[$campo]);

        $definicion = $modo === ModalidadServicio::Clases
            ? [
                'dias_prueba' => (int) $validado['dias_prueba'],
                'iva_porcentaje' => (int) $validado['iva_porcentaje'],
                'bandas' => array_map(static fn (array $b): array => ['hasta' => $b['hasta'], 'monto_minor' => (int) $b['monto_minor']], $escalones),
            ]
            : [
                'dias_prueba' => (int) $validado['dias_prueba'],
                'iva_porcentaje' => (int) $validado['iva_porcentaje'],
                'tramos' => array_map(static fn (array $t): array => ['hasta' => $t['hasta'], 'unitario_minor' => (int) $t['unitario_minor']], $escalones),
                'personas_incluidas_por_profesional' => (int) $validado['personas_incluidas_por_profesional'],
                'tope_personas_incluidas' => (int) $validado['tope_personas_incluidas'],
                'extra_por_persona_minor' => (int) $validado['extra_por_persona_minor'],
                'horas_medio_tiempo' => (int) $validado['horas_medio_tiempo'],
            ];

        // Versión siguiente bajo lock: dos publicaciones simultáneas no chocan.
        $tarifa = DB::transaction(function () use ($modo, $definicion, $validado): TarifaSaas {
            $ultima = (int) TarifaSaas::query()->where('modalidad', $modo->value)->lockForUpdate()->max('version');

            return TarifaSaas::query()->create([
                'modalidad' => $modo->value,
                'version' => $ultima + 1,
                'definicion' => $definicion,
                'vigente_desde' => isset($validado['vigente_desde']) ? $validado['vigente_desde'] : now(),
            ]);
        });

        return response()->json(['data' => $this->presentar($tarifa)], 201);
    }

    /**
     * Escalones en orden: `hasta` creciente y solo el último sin tope (el techo).
     *
     * @param  array<int, array<string, mixed>>  $escalones
     * @return list<array{hasta: int|null, monto_minor?: mixed, unitario_minor?: mixed}>
     */
    private function escalones(string $campo, array $escalones): array
    {
        $lista = array_values($escalones);
        $previo = 0;
        foreach ($lista as $i => $e) {
            $esUltimo = $i === count($lista) - 1;
            $hasta = isset($e['hasta']) ? (int) $e['hasta'] : null;
            if ($hasta === null && ! $esUltimo) {
                throw ValidationException::withMessages([$campo => ['Solo el último escalón puede quedar sin tope.']]);
            }
            if ($esUltimo && $hasta !== null) {
                throw ValidationException::withMessages([$campo => ['El último escalón debe quedar sin tope (el techo).']]);
            }
            if ($hasta !== null && $hasta <= $previo) {
                throw ValidationException::withMessages([$campo => ['Los topes deben ir de menor a mayor.']]);
            }
            $lista[$i]['hasta'] = $hasta;
            $previo = $hasta ?? $previo;
        }

        /** @var list<array{hasta: int|null, monto_minor?: mixed, unitario_minor?: mixed}> $lista */
        return $lista;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(TarifaSaas $tarifa): array
    {
        return [
            'id' => $tarifa->ulid,
            'modalidad' => $tarifa->modalidad->value,
            'version' => $tarifa->version,
            'vigente_desde' => $tarifa->vigente_desde->toIso8601String(),
            'definicion' => $tarifa->definicion,
        ];
    }
}
