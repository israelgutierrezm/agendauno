<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EmbudoNegocioTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El embudo del negocio (plan de mejoras C08): de la visita a la renovación, con su
 * cantidad y su denominador. Por omisión, los últimos 30 días.
 */
class ReporteEmbudoTenantController
{
    public function __invoke(Request $request, EmbudoNegocioTenant $embudo, FechasNegocioTenant $fechas): JsonResponse
    {
        $validado = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);
        $hoy = CarbonImmutable::parse($fechas->hoy());
        $hasta = $validado['hasta'] ?? $hoy->toDateString();
        $desde = $validado['desde'] ?? CarbonImmutable::parse($hasta)->subDays(29)->toDateString();
        abort_if(CarbonImmutable::parse($desde)->diffInDays(CarbonImmutable::parse($hasta)) > 366, 422, 'El periodo puede ser de hasta un año.');

        // Quien está acotado a unas sedes ve el embudo de esas sedes (ADR 0166).
        return response()->json(['data' => $embudo->calcular($desde, $hasta, app(ResolverAccesoTenant::class)->deLaSolicitud($request))]);
    }
}
