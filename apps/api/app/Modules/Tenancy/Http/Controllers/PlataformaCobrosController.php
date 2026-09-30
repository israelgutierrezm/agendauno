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

        $pendientes = CargoRenta::query()->where('estado', EstadoCargoRenta::Pendiente->value);
        $resumen = [
            'pendiente_minor' => (int) (clone $pendientes)->sum('monto_minor'),
            'vencido_minor' => (int) (clone $pendientes)->whereDate('vence_en', '<', $hoy)->sum('monto_minor'),
            'estudios_con_adeudo' => (clone $pendientes)->distinct()->count('estudio_id'),
            'cobrado_mes_minor' => (int) CargoRenta::query()
                ->where('estado', EstadoCargoRenta::Pagado->value)
                ->whereBetween('pagado_en', [$hoy->copy()->startOfMonth(), $hoy->copy()->endOfMonth()])
                ->sum('monto_minor'),
            'moneda' => 'MXN',
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
                'periodo' => $c->periodo,
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
