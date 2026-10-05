<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;

/**
 * Agenda del equipo (ADR 0081): por cada quien atiende, en un periodo, qué tan llena
 * estuvo su agenda y qué dejó.
 *
 * - Ocupación: minutos agendados dentro de su horario ÷ minutos disponibles
 *   ({@see OcupacionDeAgendaTenant}); null si no tiene horario de atención (p. ej.
 *   quien solo imparte clases).
 * - Clases y citas que impartió, asistentes, inasistencias y cancelaciones.
 * - Valor de lo atendido en sus sesiones, lo que se le paga (nómina, incluso cuando
 *   asiste en la de otro) y el margen ({@see ValorDeSesionesTenant}).
 *
 * Montos en minor.
 */
class CalcularAgendaEquipoTenant
{
    public function __construct(
        private readonly ValorDeSesionesTenant $valor,
        private readonly OcupacionDeAgendaTenant $ocupacion,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function calcular(string $desde, string $hasta): array
    {
        $zona = (string) (SucursalTenant::query()->value('zona_horaria') ?? config('app.timezone', 'UTC'));
        $inicio = CarbonImmutable::parse($desde.' 00:00:00', $zona)->utc();
        $fin = CarbonImmutable::parse($hasta.' 00:00:00', $zona)->addDay()->utc();
        $moneda = (string) (SucursalTenant::query()->value('moneda') ?? app(ParametrosTenant::class)->moneda());

        $sesiones = SesionTenant::query()
            ->whereBetween('inicia_en', [$inicio, $fin])
            ->where('estado', '!=', EstadoSesionTenant::Cancelada->value)
            ->with('oferta')
            ->get();
        $valores = $this->valor->calcular($sesiones);
        $ocupacion = $this->ocupacion->calcular($desde, $hasta, $sesiones)['por_profesional'];

        $filas = [];
        $fila = static fn (): array => [
            'clases' => 0, 'citas' => 0, 'agendado_min' => 0, 'disponible_min' => 0, 'agendado_en_horario_min' => 0,
            'presentes' => 0, 'ausentes' => 0, 'canceladas' => 0, 'ingreso_minor' => 0, 'costo_minor' => 0,
        ];
        foreach ($sesiones as $sesion) {
            $valor = $valores[(int) $sesion->id];
            $imparte = $valor['imparte'] ?? 0;
            $filas[$imparte] ??= $fila();
            $filas[$imparte][$sesion->tipo === TipoSesionTenant::Cita ? 'citas' : 'clases']++;
            $filas[$imparte]['agendado_min'] += $valor['minutos'];
            $filas[$imparte]['presentes'] += $valor['presentes'];
            $filas[$imparte]['ausentes'] += $valor['ausentes'];
            $filas[$imparte]['canceladas'] += $valor['canceladas'];
            $filas[$imparte]['ingreso_minor'] += $valor['ingreso'];
            foreach ($valor['pagos'] as $usuarioId => $pago) {
                $filas[$usuarioId] ??= $fila();
                $filas[$usuarioId]['costo_minor'] += $pago;
            }
        }
        foreach ($ocupacion as $usuarioId => $minutos) {
            $filas[$usuarioId] ??= $fila();
            $filas[$usuarioId]['disponible_min'] = $minutos['disponible'];
            $filas[$usuarioId]['agendado_en_horario_min'] = $minutos['agendado'];
        }

        // Quienes atienden hoy aparecen aunque no hayan tenido agenda; los que ya no
        // están (baja) solo si trabajaron en el periodo.
        $profesionales = Usuario::query()->profesionales()->pluck('id')->all();
        foreach ($profesionales as $usuarioId) {
            $filas[(int) $usuarioId] ??= $fila();
        }
        $usuarios = Usuario::withTrashed()->whereIn('id', array_keys($filas))->get()->keyBy('id');

        $lista = [];
        $totales = $fila();
        foreach ($filas as $usuarioId => $datos) {
            $usuario = $usuarios->get($usuarioId);
            $lista[] = [
                'id' => $usuario?->ulid,
                'nombre' => $usuario?->nombreCorto(),
                'foto_url' => $usuario?->fotoUrl(),
                ...$this->conIndicadores($datos),
            ];
            foreach ($totales as $clave => $total) {
                $totales[$clave] = $total + $datos[$clave];
            }
        }
        // Más valor primero; a igualdad, más agenda.
        usort($lista, static fn (array $a, array $b): int => [$b['ingreso_minor'], $b['agendado_min']] <=> [$a['ingreso_minor'], $a['agendado_min']]);

        return [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta],
            'moneda' => $moneda,
            'totales' => $this->conIndicadores($totales),
            'profesionales' => $lista,
        ];
    }

    /**
     * @param  array<string, int>  $datos
     * @return array<string, int|null>
     */
    private function conIndicadores(array $datos): array
    {
        $atendidas = $datos['presentes'] + $datos['ausentes'];

        return [
            ...$datos,
            'ocupacion_pct' => OcupacionDeAgendaTenant::porcentaje($datos['agendado_en_horario_min'], $datos['disponible_min']),
            'inasistencia_pct' => $atendidas > 0 ? (int) round($datos['ausentes'] / $atendidas * 100) : null,
            'margen_minor' => $datos['ingreso_minor'] - $datos['costo_minor'],
        ];
    }
}
