<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\EnlaceDeCitaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use Illuminate\Http\Request;

/**
 * Cambiar el horario de una cita desde el enlace de sus avisos, sin cuenta (ADR 0221):
 * lo mismo que desde la cuenta (los horarios libres del mismo servicio y profesional,
 * hasta cuántas horas antes y cuántas veces lo decide el negocio). Solo cambia de dónde
 * sale la cita: del enlace firmado, no de la sesión.
 */
class ReprogramarCitaSinCuentaController extends MiReprogramarTenantController
{
    protected function reservaPropia(Request $request): ReservaTenant
    {
        return CitaSinCuentaController::reserva($request, app(EnlaceDeCitaTenant::class));
    }
}
