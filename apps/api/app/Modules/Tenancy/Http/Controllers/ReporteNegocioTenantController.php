<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\MovimientosDePagoTenant;
use App\Modules\Tenancy\Application\OcupacionDeAgendaTenant;
use App\Modules\Tenancy\Application\ResolverAccesoTenant;
use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\AsistenciaTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reporte de negocio del estudio (R29): métricas de un periodo — dinero, órdenes
 * pagadas, ocupación, no-show, alumnos activos y ARPU — calculadas desde los datos
 * del propio tenant (consultas agregadas, sin N+1). Montos en minor (entero).
 *
 * El dinero va POR MONEDA (nunca se suman monedas distintas) y separa lo vendido, lo
 * cobrado (por la fecha del cobro, con el mostrador), lo devuelto y el neto; es el
 * mismo cálculo que los movimientos de Cobros. `ingresos_minor` es el neto de la
 * moneda principal. Quien está acotado a sedes solo ve las suyas.
 *
 * `ocupacion_agenda_pct` es la ocupación de la agenda de quienes atienden (horas
 * agendadas entre las disponibles, ADR 0081): la que importa en negocios de citas,
 * donde el cupo de cada cita es 1.
 */
class ReporteNegocioTenantController
{
    public function __invoke(
        Request $request,
        OcupacionDeAgendaTenant $ocupacion,
        MovimientosDePagoTenant $movimientos,
        FechasNegocioTenant $fechas,
        ResolverAccesoTenant $acceso,
    ): JsonResponse {
        $validado = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
        ]);

        // El periodo son días del negocio (su zona), acotados en UTC.
        $desde = CarbonImmutable::parse($validado['desde'])->toDateString();
        $hasta = CarbonImmutable::parse($validado['hasta'])->toDateString();
        $inicio = $fechas->inicioDelDia($desde);
        $fin = $fechas->finDelDia($hasta);
        $actor = $request->attributes->get('usuario_tenant');
        $sedes = $actor instanceof Usuario ? $acceso->sucursalesPermitidas($actor) : null;

        // El dinero, por moneda: vendido, cobrado, devuelto y neto.
        $dinero = $movimientos->porMoneda($desde, $hasta, $sedes);
        $principal = $dinero[0] ?? ['moneda' => 'MXN', 'ventas_minor' => 0, 'cobrado_minor' => 0, 'devuelto_minor' => 0, 'neto_minor' => 0];
        $ingresos = $principal['neto_minor'];
        // Órdenes que quedaron pagadas en el periodo (por la fecha del pago).
        $ordenesPagadas = OrdenTenant::query()
            ->where('estado', EstadoOrden::Pagada->value)
            ->whereBetween('pagada_en', [$inicio, $fin])
            ->when($sedes !== null, fn ($q) => $q->whereIn('sucursal_id', $sedes))
            ->count();

        // Clases del periodo (por hora de inicio) + capacidad.
        $sesiones = SesionTenant::query()
            ->whereBetween('inicia_en', [$inicio, $fin])
            ->when($sedes !== null, fn ($q) => $q->whereIn('sucursal_id', $sedes))
            ->get(['id', 'capacidad']);
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
            ->when($sedes !== null, fn ($q) => $q->whereIn('sucursal_id', $sedes))
            ->where('estado', '!=', EstadoSesionTenant::Cancelada->value)
            ->get())['por_profesional'];
        $disponible = array_sum(array_column($agenda, 'disponible'));
        $agendado = array_sum(array_column($agenda, 'agendado'));

        return response()->json(['data' => [
            'periodo' => ['desde' => $validado['desde'], 'hasta' => $validado['hasta']],
            'moneda' => $principal['moneda'],
            // Neto (cobrado − devuelto) de la moneda principal; el detalle, por moneda.
            'ingresos_minor' => $ingresos,
            'dinero_por_moneda' => $dinero,
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
