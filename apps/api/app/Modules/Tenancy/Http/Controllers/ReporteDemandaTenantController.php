<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CalcularDemandaTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reporte de DEMANDA (R31): mapa día×hora y desglose por actividad de la ocupación y
 * la presión de lista de espera en un periodo. El cálculo vive en {@see CalcularDemandaTenant}.
 */
class ReporteDemandaTenantController
{
    public function __construct(private readonly CalcularDemandaTenant $calculadora) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ]);

        return response()->json(['data' => $this->calculadora->calcular(
            $validado['desde'],
            $validado['hasta'],
            // Quien está acotado a sucursales solo ve lo de las suyas.
            app(ResolverAccesoTenant::class)->deLaSolicitud($request),
        )]);
    }
}
