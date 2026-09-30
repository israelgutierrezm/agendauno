<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Analítica de demanda (R31): sobre las sesiones NO canceladas de un periodo, mide
 * cuánta demanda hubo frente a la capacidad, desglosada por día de semana × hora y
 * por actividad. Dos señales explicables:
 *  - `confirmadas`: lugares tomados (reservas confirmadas) → ocupación.
 *  - `espera`: presión de lista de espera = reservas que superaron el cupo
 *    (en_espera + ofrecida + expirada), es decir demanda que NO cupo.
 * El bucketeo por día×hora se hace en PHP con la zona horaria SNAPSHOT de cada
 * sesión (multi-sucursal, multi-zona; y portable a SQLite del tenant).
 *
 * Cada franja trae además la ocupación de la agenda de quienes atienden
 * (`disponible_min`, `agendado_min`, `utilizacion_pct`; ADR 0081): en citas el cupo
 * es 1 y la ocupación por cupo no dice nada. Aparecen también las franjas con
 * horario y sin nada agendado.
 */
class CalcularDemandaTenant
{
    public function __construct(private readonly OcupacionDeAgendaTenant $ocupacion) {}

    // Reservas que en algún momento pasaron por lista de espera = demanda no satisfecha.
    // Instancias de enum (no strings): `estado` viene casteado a EstadoReserva.
    private const ESTADOS_ESPERA = [
        EstadoReserva::EnEspera,
        EstadoReserva::Ofrecida,
        EstadoReserva::Expirada,
    ];

    /**
     * @return array<string, mixed>
     */
    public function calcular(string $desde, string $hasta): array
    {
        $zona = (string) (SucursalTenant::query()->value('zona_horaria') ?? config('app.timezone', 'UTC'));
        $inicio = CarbonImmutable::parse($desde.' 00:00:00', $zona)->utc();
        $fin = CarbonImmutable::parse($hasta.' 00:00:00', $zona)->addDay()->utc();

        $sesiones = SesionTenant::query()
            ->whereBetween('inicia_en', [$inicio, $fin])
            ->where('estado', '!=', EstadoSesionTenant::Cancelada->value)
            ->with('oferta.actividad')
            ->get();
        $sesionIds = $sesiones->pluck('id')->all();

        // Reservas de esas sesiones (solo sesion_id + estado): se tallan en PHP.
        /** @var Collection<int, Collection<int, ReservaTenant>> $reservasPorSesion */
        $reservasPorSesion = ReservaTenant::query()
            ->whereIn('sesion_id', $sesionIds)
            ->get(['sesion_id', 'estado'])
            ->groupBy('sesion_id');

        /** @var array<string, array<string, int>> $matriz  clave "dia-hora" */
        $matriz = [];
        /** @var array<string, array<string, mixed>> $actividades */
        $actividades = [];
        $totales = ['sesiones' => 0, 'capacidad' => 0, 'confirmadas' => 0, 'espera' => 0];

        foreach ($sesiones as $sesion) {
            /** @var Collection<int, ReservaTenant> $reservas */
            $reservas = $reservasPorSesion->get($sesion->id) ?? collect();
            $confirmadas = $reservas->where('estado', EstadoReserva::Confirmada)->count();
            $espera = $reservas->whereIn('estado', self::ESTADOS_ESPERA)->count();
            $capacidad = (int) ($sesion->capacidad ?? 0);

            // Día de semana (1=lun..7=dom) y hora local, según la zona snapshot.
            $local = $sesion->inicia_en->copy()->setTimezone($sesion->zona_horaria ?? $zona);
            $claveCelda = $local->dayOfWeekIso.'-'.$local->hour;
            if (! isset($matriz[$claveCelda])) {
                $matriz[$claveCelda] = ['dia' => $local->dayOfWeekIso, 'hora' => $local->hour, 'sesiones' => 0, 'capacidad' => 0, 'confirmadas' => 0, 'espera' => 0];
            }
            $matriz[$claveCelda]['sesiones']++;
            $matriz[$claveCelda]['capacidad'] += $capacidad;
            $matriz[$claveCelda]['confirmadas'] += $confirmadas;
            $matriz[$claveCelda]['espera'] += $espera;

            // Agrupado por actividad (rollup de la oferta a su actividad).
            $actividad = $sesion->oferta->actividad;
            $claveAct = $actividad->ulid;
            if (! isset($actividades[$claveAct])) {
                $actividades[$claveAct] = [
                    'id' => $actividad->ulid,
                    'actividad' => $actividad->nombre,
                    'sesiones' => 0, 'capacidad' => 0, 'confirmadas' => 0, 'espera' => 0,
                ];
            }
            $actividades[$claveAct]['sesiones']++;
            $actividades[$claveAct]['capacidad'] += $capacidad;
            $actividades[$claveAct]['confirmadas'] += $confirmadas;
            $actividades[$claveAct]['espera'] += $espera;

            $totales['sesiones']++;
            $totales['capacidad'] += $capacidad;
            $totales['confirmadas'] += $confirmadas;
            $totales['espera'] += $espera;
        }

        // Ocupación de la agenda por franja, con las horas disponibles sin nada agendado.
        $totales['disponible_min'] = 0;
        $totales['agendado_min'] = 0;
        foreach ($this->ocupacion->calcular($desde, $hasta, $sesiones)['por_franja'] as $clave => $franja) {
            $matriz[$clave] ??= ['dia' => $franja['dia'], 'hora' => $franja['hora'], 'sesiones' => 0, 'capacidad' => 0, 'confirmadas' => 0, 'espera' => 0];
            $matriz[$clave]['disponible_min'] = $franja['disponible'];
            $matriz[$clave]['agendado_min'] = $franja['agendado'];
            $totales['disponible_min'] += $franja['disponible'];
            $totales['agendado_min'] += $franja['agendado'];
        }

        $celdas = array_map(fn (array $c): array => $this->conOcupacion($c), array_values($matriz));
        // Orden estable: por día y luego por hora.
        usort($celdas, static fn (array $a, array $b): int => [$a['dia'], $a['hora']] <=> [$b['dia'], $b['hora']]);

        $listaActividades = array_map(fn (array $a): array => $this->conOcupacion($a), array_values($actividades));
        // Más demanda primero (accionable: dónde concentrar recursos / abrir cupos).
        usort($listaActividades, static fn (array $a, array $b): int => [$b['confirmadas'], $b['espera']] <=> [$a['confirmadas'], $a['espera']]);

        return [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'totales' => $this->conOcupacion($totales),
            'matriz' => $celdas,
            'actividades' => $listaActividades,
        ];
    }

    /**
     * Añade `ocupacion_pct` (confirmadas/capacidad, null si no hay capacidad medible)
     * y, en franjas y totales, `utilizacion_pct` de la agenda (null sin horario).
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function conOcupacion(array $fila): array
    {
        $capacidad = (int) $fila['capacidad'];
        $fila['ocupacion_pct'] = $capacidad > 0 ? (int) round((int) $fila['confirmadas'] / $capacidad * 100) : null;
        if (array_key_exists('disponible_min', $fila) || array_key_exists('dia', $fila)) {
            $fila['disponible_min'] = (int) ($fila['disponible_min'] ?? 0);
            $fila['agendado_min'] = (int) ($fila['agendado_min'] ?? 0);
            $fila['utilizacion_pct'] = OcupacionDeAgendaTenant::porcentaje($fila['agendado_min'], $fila['disponible_min']);
        }

        return $fila;
    }
}
