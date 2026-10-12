<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\ReporteCampanasTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El reporte por campaña del negocio (ADR 0144): solicitudes, compradores nuevos,
 * compras, devoluciones, primeras reservas y, aparte, renovaciones, por la campaña que
 * se acredita. Por omisión, los últimos 30 días (como el embudo).
 */
class ReporteCampanasTenantController
{
    public function __invoke(Request $request, ReporteCampanasTenant $reporte, FechasNegocioTenant $fechas): JsonResponse
    {
        $validado = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);
        $hoy = CarbonImmutable::parse($fechas->hoy());
        $hasta = $validado['hasta'] ?? $hoy->toDateString();
        $desde = $validado['desde'] ?? CarbonImmutable::parse($hasta)->subDays(29)->toDateString();
        abort_if(CarbonImmutable::parse($desde)->diffInDays(CarbonImmutable::parse($hasta)) > 366, 422, 'El periodo puede ser de hasta un año.');

        return response()->json(['data' => $reporte->calcular($desde, $hasta)]);
    }
}
