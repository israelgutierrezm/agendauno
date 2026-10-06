<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CalcularRentabilidadTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reporte de RENTABILIDAD por clase (R30): por oferta, ingreso vs costo de instructor y
 * margen en un periodo. El cálculo vive en {@see CalcularRentabilidadTenant}.
 */
class ReporteRentabilidadTenantController
{
    public function __construct(private readonly CalcularRentabilidadTenant $calculadora) {}

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
