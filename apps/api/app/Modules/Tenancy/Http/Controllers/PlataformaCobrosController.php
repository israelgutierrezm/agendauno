<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\EstadoCargoRenta;
use App\Modules\Tenancy\Models\CargoRenta;
use App\Modules\Tenancy\Models\FacturaPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Cobro de la renta del SaaS visto por el operador: lo que se debe, lo vencido y lo
 * cobrado en el mes, y los cargos de todos los estudios (filtrables por estado y
 * periodo) con su factura.
 */
class PlataformaCobrosController
{
    private const LIMITE = 200;

    public function __invoke(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'estado' => ['nullable', Rule::enum(EstadoCargoRenta::class)],
            'periodo' => ['nullable', 'date_format:Y-m'],
            // Solo las rentas vencidas y sin pagar (para decidir si se suspende).
            'vencidos' => ['nullable', 'boolean'],
        ]);
        $hoy = Carbon::today();

        // Por moneda (ADR 0107: a México en pesos, al resto en dólares): no se suman
        // pesos con dólares. Arriba, los pesos; las demás monedas, aparte.
        $pendientes = CargoRenta::query()->where('estado', EstadoCargoRenta::Pendiente->value);
        $totales = function (string $moneda) use ($pendientes, $hoy): array {
            $deMoneda = (clone $pendientes)->where('moneda', $moneda);

            return [
                'moneda' => $moneda,
                'pendiente_minor' => (int) (clone $deMoneda)->sum('monto_minor'),
                'vencido_minor' => (int) (clone $deMoneda)->whereDate('vence_en', '<', $hoy)->sum('monto_minor'),
                'cobrado_mes_minor' => (int) CargoRenta::query()
                    ->where('estado', EstadoCargoRenta::Pagado->value)
                    ->where('moneda', $moneda)
                    ->whereBetween('pagado_en', [$hoy->copy()->startOfMonth(), $hoy->copy()->endOfMonth()])
                    ->sum('monto_minor'),
            ];
        };
        $otras = CargoRenta::query()->where('moneda', '!=', 'MXN')->distinct()->pluck('moneda')
            ->map(fn (string $moneda): array => $totales($moneda))
            ->filter(fn (array $t): bool => $t['pendiente_minor'] > 0 || $t['cobrado_mes_minor'] > 0)
            ->values()->all();
        $resumen = [
            ...$totales('MXN'),
            'estudios_con_adeudo' => (clone $pendientes)->distinct()->count('estudio_id'),
            'otras_monedas' => $otras,
        ];

        $cargos = CargoRenta::query()
            ->with('estudio')
            ->when(isset($validado['estado']), fn ($q) => $q->where('estado', $validado['estado']))
            ->when(isset($validado['periodo']), fn ($q) => $q->where('periodo', $validado['periodo']))
            ->when($request->boolean('vencidos'), fn ($q) => $q
                ->where('estado', EstadoCargoRenta::Pendiente->value)
                ->whereDate('vence_en', '<', $hoy))
            ->orderByDesc('periodo')
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        $facturas = FacturaPlataforma::query()
            ->whereIn('cargo_renta_id', $cargos->modelKeys())
            ->get()
            ->keyBy('cargo_renta_id');

        return response()->json([
            'resumen' => $resumen,
            'data' => $cargos->map(static fn (CargoRenta $c): array => [
                'id' => $c->ulid,
                'estudio' => $c->estudio?->nombre,
                'estudio_slug' => $c->estudio?->slug,
                // Para suspenderlo (o ver que ya lo está) desde la lista.
                'estudio_estado' => $c->estudio?->estado->value,
                'estudio_suspendido_por' => $c->estudio?->suspendido_por,
                'periodo' => $c->periodo,
                // `renta`, `plan` (por adelantado), `ajuste` (cambio de plan) o `timbres`.
                'concepto' => $c->concepto ?? 'renta',
                'cubre_desde' => $c->cubre_desde?->toDateString(),
                'cubre_hasta' => $c->cubre_hasta?->toDateString(),
                'monto_minor' => $c->monto_minor,
                'moneda' => $c->moneda,
                'estado' => $c->estado->value,
                'vence_en' => $c->vence_en?->toDateString(),
                'vencido' => $c->estado === EstadoCargoRenta::Pendiente && $c->vence_en !== null && $c->vence_en->lt($hoy),
                'pagado_en' => $c->pagado_en?->toIso8601String(),
                'factura' => $facturas->get($c->getKey())?->estado->value,
            ])->values()->all(),
        ]);
    }
}
