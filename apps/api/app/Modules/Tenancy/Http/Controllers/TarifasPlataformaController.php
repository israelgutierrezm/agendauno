<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\TarifaSaas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tarifas del SaaS por modalidad (superadmin, ADR 0019 y 0107). Las tarifas son
 * VERSIONADAS: publicar cambios crea una versión nueva (nunca se edita una publicada)
 * y cada cargo guarda la versión con que se calculó. Dinero en minor, sin IVA, en la
 * moneda de la tarifa (USD; a México se le cobra en pesos al tipo de cambio).
 *
 * - Clases: escalera por alumnos activos (`bandas`), mes vencido.
 * - Citas: precio mensual por nivel y profesionales contratados (`niveles`), por
 *   adelantado; el anual cuesta `meses_anual` meses.
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
            'moneda' => ['nullable', Rule::in(['USD', 'MXN'])],
            'dias_prueba' => ['required', 'integer', 'min:0', 'max:365'],
            'iva_porcentaje' => ['required', 'integer', 'min:0', 'max:50'],
            // IVA a negocios fuera de México (exportación de servicios: 0 % por omisión).
            'iva_porcentaje_extranjero' => ['nullable', 'integer', 'min:0', 'max:50'],
            'vigente_desde' => ['nullable', 'date', 'after_or_equal:today'],
        ];
        $reglas = $modo === ModalidadServicio::Clases
            ? $comunes + [
                'bandas' => ['required', 'array', 'min:1', 'max:30'],
                'bandas.*.hasta' => ['nullable', 'integer', 'min:1'],
                'bandas.*.monto_minor' => ['required', 'integer', 'min:0', 'max:100000000'],
            ]
            : $comunes + [
                'meses_anual' => ['required', 'integer', 'min:1', 'max:12'],
                'niveles' => ['required', 'array:individual,premium,pro'],
                'niveles.individual' => ['required', 'array', 'size:1'],
                'niveles.premium' => ['required', 'array', 'min:1', 'max:100'],
                'niveles.pro' => ['required', 'array', 'min:1', 'max:100'],
                'niveles.*.*' => ['required', 'integer', 'min:0', 'max:100000000'],
                // Qué nivel abre cada función (ADR 0107); lo que no venga, el de siempre.
                'funciones' => ['nullable', 'array:'.implode(',', array_keys(FuncionesPlan::NIVEL_MINIMO))],
                'funciones.*' => ['required', Rule::in(array_keys(FuncionesPlan::ORDEN))],
            ];
        $validado = $request->validate($reglas);

        $comun = [
            'moneda' => (string) ($validado['moneda'] ?? 'USD'),
            'dias_prueba' => (int) $validado['dias_prueba'],
            'iva_porcentaje' => (int) $validado['iva_porcentaje'],
            'iva_porcentaje_extranjero' => (int) ($validado['iva_porcentaje_extranjero'] ?? 0),
        ];
        $definicion = $modo === ModalidadServicio::Clases
            ? $comun + [
                'bandas' => array_map(static fn (array $b): array => ['hasta' => $b['hasta'], 'monto_minor' => (int) $b['monto_minor']], $this->escalones('bandas', $validado['bandas'])),
            ]
            : $comun + [
                'meses_anual' => (int) $validado['meses_anual'],
                'niveles' => $this->niveles($validado['niveles']),
                'funciones' => FuncionesPlan::mapa(['funciones' => $validado['funciones'] ?? []]),
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
     * Precios por nivel: Individual con un profesional; Premium y Pro con los mismos
     * profesionales, seguidos desde 2 (más allá del último: cotización).
     *
     * @param  array<string, array<int|string, mixed>>  $niveles
     * @return array{individual: array<int|string, int>, premium: array<int|string, int>, pro: array<int|string, int>}
     */
    private function niveles(array $niveles): array
    {
        $individual = $niveles['individual'];
        if (array_keys($individual) !== [1] && array_keys($individual) !== ['1']) {
            throw ValidationException::withMessages(['niveles.individual' => ['El plan Individual es para un profesional.']]);
        }
        $resultado = ['individual' => ['1' => (int) reset($individual)], 'premium' => [], 'pro' => []];
        $cantidades = null;
        foreach (['premium', 'pro'] as $nivel) {
            $precios = $niveles[$nivel];
            $claves = array_map('intval', array_keys($precios));
            sort($claves);
            if ($claves === [] || $claves[0] !== 2 || $claves !== range(2, 1 + count($claves))) {
                throw ValidationException::withMessages(["niveles.{$nivel}" => ['Los precios van por profesionales seguidos, desde 2.']]);
            }
            if ($cantidades !== null && $claves !== $cantidades) {
                throw ValidationException::withMessages(["niveles.{$nivel}" => ['Premium y Pro deben tener los mismos profesionales.']]);
            }
            $cantidades = $claves;
            foreach ($claves as $profesionales) {
                $resultado[$nivel][$profesionales] = (int) $precios[$profesionales];
            }
        }

        return $resultado;
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
        $definicion = $tarifa->definicion;
        // Citas por niveles: con el reparto de funciones completo (lo que la tarifa no
        // fijó, el de siempre).
        if (is_array($definicion['niveles'] ?? null)) {
            $definicion['funciones'] = FuncionesPlan::mapa($definicion);
        }

        return [
            'id' => $tarifa->ulid,
            'modalidad' => $tarifa->modalidad->value,
            'version' => $tarifa->version,
            'vigente_desde' => $tarifa->vigente_desde->toIso8601String(),
            'definicion' => $definicion,
        ];
    }
}
