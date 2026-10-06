<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CalcularAgendaEquipoTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Agenda del equipo (ADR 0081): por profesional, ocupación de su agenda, clases y
 * citas, asistencia, valor de lo atendido, su pago y el margen en un periodo. El
 * cálculo vive en {@see CalcularAgendaEquipoTenant}.
 */
class ReporteEquipoTenantController
{
    /** Recorre el periodo día por día: hasta un año. */
    private const DIAS_MAXIMOS = 366;

    public function __construct(private readonly CalcularAgendaEquipoTenant $calculadora) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validado = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ]);
        if (CarbonImmutable::parse($validado['desde'])->diffInDays(CarbonImmutable::parse($validado['hasta'])) >= self::DIAS_MAXIMOS) {
            throw ValidationException::withMessages(['hasta' => ['Elige un periodo de un año o menos.']]);
        }

        return response()->json(['data' => $this->calculadora->calcular(
            $validado['desde'],
            $validado['hasta'],
            // Quien está acotado a sucursales solo ve lo de las suyas.
            app(ResolverAccesoTenant::class)->deLaSolicitud($request),
        )]);
    }
}
