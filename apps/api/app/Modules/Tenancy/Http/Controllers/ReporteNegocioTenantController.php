<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\OcupacionDeAgendaTenant;
use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\AsistenciaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reporte de negocio del estudio (R29): métricas de un periodo — ingresos, órdenes
 * pagadas, ocupación, no-show, alumnos activos y ARPU — calculadas desde los datos
 * del propio tenant (consultas agregadas, sin N+1). Montos en minor (entero).
 *
 * `ocupacion_agenda_pct` es la ocupación de la agenda de quienes atienden (horas
 * agendadas entre las disponibles, ADR 0081): la que importa en negocios de citas,
 * donde el cupo de cada cita es 1.
 */
class ReporteNegocioTenantController
{
    public function __invoke(Request $request, OcupacionDeAgendaTenant $ocupacion): JsonResponse
    {
        $validado = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ]);

        // El periodo se interpreta en la zona de una sucursal (o del sistema) y se
        // acota en UTC, para no cortar por el desfase de zona.
        $zona = (string) (SucursalTenant::query()->value('zona_horaria') ?? config('app.timezone', 'UTC'));
        $inicio = CarbonImmutable::parse($validado['desde'].' 00:00:00', $zona)->utc();
        $fin = CarbonImmutable::parse($validado['hasta'].' 00:00:00', $zona)->addDay()->utc();

        // Ingresos = órdenes pagadas en el periodo (incluye ventanilla).
        $ordenes = OrdenTenant::query()
            ->where('estado', EstadoOrden::Pagada->value)
            ->whereBetween('created_at', [$inicio, $fin]);
        $ingresos = (int) (clone $ordenes)->sum('total_minor');
        $ordenesPagadas = (clone $ordenes)->count();
        $moneda = (string) ((clone $ordenes)->value('moneda') ?? 'MXN');

        // Clases del periodo (por hora de inicio) + capacidad.
        $sesiones = SesionTenant::query()->whereBetween('inicia_en', [$inicio, $fin])->get(['id', 'capacidad']);
        $sesionIds = $sesiones->pluck('id');
        $capacidad = (int) $sesiones->sum(fn (SesionTenant $s): int => (int) ($s->capacidad ?? 0));

        // Reservas confirmadas de esas clases: ocupación y alumnos activos.
        $reservas = ReservaTenant::query()
            ->whereIn('sesion_id', $sesionIds)
            ->where('estado', EstadoReserva::Confirmada->value);
        $confirmadas = (clone $reservas)->count();
        $alumnosActivos = (int) (clone $reservas)->distinct()->count('persona_id');
        $reservaIds = (clone $reservas)->pluck('id');

        // Asistencia (no-show) de esas reservas.
        $presentes = AsistenciaTenant::query()->whereIn('reserva_id', $reservaIds)->where('estado', EstadoAsistencia::Presente->value)->count();
        $ausentes = AsistenciaTenant::query()->whereIn('reserva_id', $reservaIds)->where('estado', EstadoAsistencia::Ausente->value)->count();
        $marcadas = $presentes + $ausentes;

        $agenda = $ocupacion->calcular($validado['desde'], $validado['hasta'], SesionTenant::query()
            ->whereBetween('inicia_en', [$inicio, $fin])
            ->where('estado', '!=', EstadoSesionTenant::Cancelada->value)
            ->get())['por_profesional'];
        $disponible = array_sum(array_column($agenda, 'disponible'));
        $agendado = array_sum(array_column($agenda, 'agendado'));

        return response()->json(['data' => [
            'periodo' => ['desde' => $validado['desde'], 'hasta' => $validado['hasta']],
            'moneda' => $moneda,
            'ingresos_minor' => $ingresos,
            'ordenes_pagadas' => $ordenesPagadas,
            'clases' => $sesiones->count(),
            'capacidad_total' => $capacidad,
            'confirmadas' => $confirmadas,
            'ocupacion_pct' => $capacidad > 0 ? (int) round($confirmadas / $capacidad * 100) : null,
            'ocupacion_agenda_pct' => OcupacionDeAgendaTenant::porcentaje($agendado, $disponible),
            'presentes' => $presentes,
            'ausentes' => $ausentes,
            'no_show_pct' => $marcadas > 0 ? (int) round($ausentes / $marcadas * 100) : null,
            'alumnos_activos' => $alumnosActivos,
            'arpu_minor' => $alumnosActivos > 0 ? (int) round($ingresos / $alumnosActivos) : null,
        ]]);
    }
}
