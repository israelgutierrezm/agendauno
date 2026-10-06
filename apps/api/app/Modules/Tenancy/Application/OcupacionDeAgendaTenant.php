<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\AsignacionSesionTenant;
use App\Modules\Tenancy\Models\BloqueoAgendaTenant;
use App\Modules\Tenancy\Models\ExcepcionHorarioTenant;
use App\Modules\Tenancy\Models\HorarioAtencionTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Ocupación de la agenda de quienes atienden (ADR 0081): cuánto tiempo tenían
 * disponible y cuánto de ese tiempo estuvo agendado, por profesional y por franja
 * (día de la semana × hora local).
 *
 * - Disponible: sus horarios de atención en cada sede, cada día del periodo, menos los
 *   días que el negocio cierra y sus bloqueos (los suyos y los de toda la sede).
 * - Agendado: la parte de sus clases y citas (no canceladas) que cae dentro de ese
 *   horario, así la ocupación nunca pasa de 100%. Cuenta para quien la imparte
 *   ({@see PersonalDeSesionTenant}).
 *
 * Sirve sobre todo en negocios de citas, donde cada cita tiene cupo 1 y la ocupación
 * por cupo no dice nada. Minutos enteros.
 */
class OcupacionDeAgendaTenant
{
    /**
     * @param  Collection<int, SesionTenant>  $sesiones  no canceladas del periodo
     * @param  list<int>|null  $sucursales  solo los horarios de estas sedes (null = todas)
     * @return array{por_profesional: array<int, array{disponible: int, agendado: int}>, por_franja: array<string, array{dia: int, hora: int, disponible: int, agendado: int}>}
     */
    public function calcular(string $desde, string $hasta, Collection $sesiones, ?array $sucursales = null): array
    {
        $ventanas = $this->ventanas($desde, $hasta, $sucursales);
        $asignaciones = AsignacionSesionTenant::query()
            ->whereIn('sesion_id', $sesiones->pluck('id'))
            ->get()
            ->groupBy('sesion_id');

        $porProfesional = [];
        $porFranja = [];
        foreach ($ventanas as $usuarioId => $tramos) {
            foreach ($tramos as [$inicia, $termina, $zona]) {
                $porProfesional[$usuarioId]['disponible'] = ($porProfesional[$usuarioId]['disponible'] ?? 0) + $this->minutos($inicia, $termina);
                $porProfesional[$usuarioId]['agendado'] ??= 0;
                $this->repartir($porFranja, 'disponible', $inicia, $termina, $zona);
            }
        }

        foreach ($sesiones as $sesion) {
            $usuarioId = PersonalDeSesionTenant::imparte($sesion, $asignaciones->get($sesion->id) ?? collect());
            foreach ($usuarioId !== null ? ($ventanas[$usuarioId] ?? []) : [] as [$inicia, $termina, $zona]) {
                $desdeCruce = $sesion->inicia_en->toImmutable()->max($inicia);
                $hastaCruce = $sesion->termina_en->toImmutable()->min($termina);
                if ($desdeCruce->lessThan($hastaCruce)) {
                    $porProfesional[$usuarioId]['agendado'] += $this->minutos($desdeCruce, $hastaCruce);
                    $this->repartir($porFranja, 'agendado', $desdeCruce, $hastaCruce, $zona);
                }
            }
        }

        return ['por_profesional' => $porProfesional, 'por_franja' => $porFranja];
    }

    /** Porcentaje de lo disponible que estuvo agendado; null si no había horario. */
    public static function porcentaje(int $agendado, int $disponible): ?int
    {
        return $disponible > 0 ? (int) round(min($agendado, $disponible) / $disponible * 100) : null;
    }

    /**
     * Los tramos disponibles de cada profesional, en UTC, con la zona de su sede.
     *
     * @param  list<int>|null  $sucursales
     * @return array<int, list<array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}>>
     */
    private function ventanas(string $desde, string $hasta, ?array $sucursales): array
    {
        $horarios = HorarioAtencionTenant::query()
            ->when($sucursales !== null, fn ($q) => $q->whereIn('sucursal_id', $sucursales))
            ->get()
            ->groupBy('dia_semana');
        if ($horarios->isEmpty()) {
            return [];
        }
        $zonas = SucursalTenant::query()->pluck('zona_horaria', 'id');
        $zonaNegocio = app(FechasNegocioTenant::class)->zona();
        $primerDia = CarbonImmutable::parse($desde);
        $ultimoDia = CarbonImmutable::parse($hasta);

        $cerrados = ExcepcionHorarioTenant::query()
            ->whereDate('fecha', '>=', $primerDia->toDateString())
            ->whereDate('fecha', '<=', $ultimoDia->toDateString())
            ->get(['fecha'])
            ->map(static fn (ExcepcionHorarioTenant $e): string => CarbonImmutable::parse((string) $e->fecha)->toDateString())
            ->all();
        $bloqueos = BloqueoAgendaTenant::query()
            ->whereNull('recurso_id')
            ->where('desde', '<', $ultimoDia->addDays(2))
            ->where('hasta', '>', $primerDia->subDay())
            ->get();

        $ventanas = [];
        for ($dia = $primerDia; $dia->lessThanOrEqualTo($ultimoDia); $dia = $dia->addDay()) {
            $fecha = $dia->toDateString();
            if (in_array($fecha, $cerrados, true)) {
                continue;
            }
            foreach ($horarios->get($dia->isoWeekday()) ?? [] as $horario) {
                $zona = (string) ($zonas[$horario->sucursal_id] ?? $zonaNegocio);
                $tramos = [[
                    CarbonImmutable::parse($fecha.' '.$horario->hora_inicio, $zona)->utc(),
                    CarbonImmutable::parse($fecha.' '.$horario->hora_fin, $zona)->utc(),
                ]];
                foreach ($bloqueos as $bloqueo) {
                    $leToca = (int) $bloqueo->instructor_id === (int) $horario->instructor_id
                        || ($bloqueo->instructor_id === null && (int) $bloqueo->sucursal_id === (int) $horario->sucursal_id);
                    if ($leToca) {
                        $tramos = $this->restar($tramos, $bloqueo->desde->toImmutable(), $bloqueo->hasta->toImmutable());
                    }
                }
                foreach ($tramos as [$inicia, $termina]) {
                    $ventanas[(int) $horario->instructor_id][] = [$inicia, $termina, $zona];
                }
            }
        }

        return $ventanas;
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $tramos
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function restar(array $tramos, CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $quedan = [];
        foreach ($tramos as [$inicia, $termina]) {
            if ($hasta->lessThanOrEqualTo($inicia) || $desde->greaterThanOrEqualTo($termina)) {
                $quedan[] = [$inicia, $termina];

                continue;
            }
            if ($desde->greaterThan($inicia)) {
                $quedan[] = [$inicia, $desde];
            }
            if ($hasta->lessThan($termina)) {
                $quedan[] = [$hasta, $termina];
            }
        }

        return $quedan;
    }

    /**
     * Reparte los minutos de un tramo en sus horas locales (un tramo de 10:30 a 11:15
     * suma 30 a las 10 y 15 a las 11).
     *
     * @param  array<string, array{dia: int, hora: int, disponible: int, agendado: int}>  $franjas
     * @param  'disponible'|'agendado'  $campo
     *
     * @param-out array<string, array{dia: int, hora: int, disponible: int, agendado: int}> $franjas
     */
    private function repartir(array &$franjas, string $campo, CarbonImmutable $inicia, CarbonImmutable $termina, string $zona): void
    {
        $cursor = $inicia->setTimezone($zona);
        $fin = $termina->setTimezone($zona);
        while ($cursor->lessThan($fin)) {
            $siguiente = $cursor->startOfHour()->addHour()->min($fin);
            $clave = $cursor->isoWeekday().'-'.$cursor->hour;
            $franja = $franjas[$clave] ?? ['dia' => $cursor->isoWeekday(), 'hora' => $cursor->hour, 'disponible' => 0, 'agendado' => 0];
            $minutos = $this->minutos($cursor, $siguiente);
            if ($campo === 'disponible') {
                $franja['disponible'] += $minutos;
            } else {
                $franja['agendado'] += $minutos;
            }
            $franjas[$clave] = $franja;
            $cursor = $siguiente;
        }
    }

    private function minutos(CarbonImmutable $desde, CarbonImmutable $hasta): int
    {
        return (int) round($desde->diffInSeconds($hasta, true) / 60);
    }
}
