<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\ExcepcionHorarioTenant;
use App\Modules\Tenancy\Models\HorarioAtencionTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\SucursalTenant;
use Carbon\CarbonImmutable;

/**
 * Motor de disponibilidad para citas (F-08): calcula los HUECOS LIBRES de un proveedor
 * (instructor/barbero) en una fecha, para un servicio de cierta duración. Los huecos
 * salen de sus ventanas de atención ({@see HorarioAtencionTenant}, hora local de la
 * sucursal) troceadas en pasos, descartando los que ya inician o chocan con una
 * clase/cita del instructor (reusa {@see VerificarAgendaTenant}). Devuelve horas en UTC.
 */
class CalcularDisponibilidadTenant
{
    public function __construct(
        private readonly VerificarAgendaTenant $agenda,
        private readonly ElegirRecursoTenant $recursos,
    ) {}

    /**
     * Duración y márgenes de una consulta: los del servicio si se indica; si no, la
     * duración que manda la pantalla, sin márgenes propios (los de las sesiones que ya
     * están en la agenda se respetan igual).
     *
     * @return array{0: int, 1: MargenesServicio}
     */
    public static function duracionYMargenes(?OfertaTenant $oferta, ?int $duracionMin): array
    {
        $duracion = $oferta !== null && (int) $oferta->duracion_minutos > 0
            ? (int) $oferta->duracion_minutos
            : ($duracionMin !== null && $duracionMin > 0 ? $duracionMin : 30);

        return [$duracion, MargenesServicio::de($oferta)];
    }

    /**
     * Huecos de atención libres. Con márgenes, un hueco no se ofrece si su
     * preparación o limpieza invadiría otra sesión, y el paso por defecto los incluye
     * (una cita tras otra, con su tiempo entre ellas).
     *
     * @return list<array{inicia: string, termina: string}> ISO-8601 UTC
     */
    public function paraFecha(int $instructorId, SucursalTenant $sucursal, string $fecha, int $duracionMin, ?int $pasoMin = null, ?MargenesServicio $margenes = null, ?OfertaTenant $servicio = null): array
    {
        $margenes ??= new MargenesServicio;
        // Si el servicio requiere cabina o equipo (2.4), el hueco necesita uno libre.
        $conRecurso = $servicio !== null && $this->recursos->requiere($servicio) ? $servicio : null;
        // Día cerrado del negocio (feriado, cierre): no se ofrecen citas.
        if (ExcepcionHorarioTenant::query()->whereDate('fecha', $fecha)->exists()) {
            return [];
        }

        $paso = $pasoMin !== null && $pasoMin > 0 ? $pasoMin : $duracionMin + $margenes->total();
        $zona = (string) ($sucursal->zona_horaria ?? config('app.timezone', 'UTC'));
        $diaSemana = (int) CarbonImmutable::parse($fecha, $zona)->isoWeekday();

        $ventanas = HorarioAtencionTenant::query()
            ->where('instructor_id', $instructorId)
            ->where('sucursal_id', $sucursal->getKey())
            ->where('dia_semana', $diaSemana)
            ->orderBy('hora_inicio')
            ->get();

        $ahora = CarbonImmutable::now();
        $slots = [];

        foreach ($ventanas as $ventana) {
            $finVentana = CarbonImmutable::parse($fecha.' '.$ventana->hora_fin, $zona);
            $cursor = CarbonImmutable::parse($fecha.' '.$ventana->hora_inicio, $zona);

            // Trocea la ventana en pasos; cada hueco debe caber completo antes del fin.
            while ($cursor->addMinutes($duracionMin)->lessThanOrEqualTo($finVentana)) {
                $inicia = $cursor->utc();
                $termina = $cursor->addMinutes($duracionMin)->utc();

                // Solo huecos futuros y sin conflicto de agenda del instructor.
                if ($inicia->greaterThan($ahora)
                    && $this->agenda->conflictos($instructorId, null, $inicia, $termina, sucursalId: (int) $sucursal->getKey(), margenes: $margenes) === []
                    && ($conRecurso === null || $this->recursos->libre($conRecurso, (int) $sucursal->getKey(), $inicia, $termina, $margenes) !== null)) {
                    $slots[] = [
                        'inicia' => $inicia->toIso8601String(),
                        'termina' => $termina->toIso8601String(),
                    ];
                }

                $cursor = $cursor->addMinutes($paso);
            }
        }

        return $slots;
    }

    /**
     * ¿El intervalo cae completo en una ventana de atención del profesional en esa
     * sucursal, en un día que no está cerrado? (Lo que la pantalla ofreció como
     * hueco; el servidor lo vuelve a exigir al agendar.)
     */
    public function cabeEnHorario(int $instructorId, SucursalTenant $sucursal, CarbonImmutable $inicia, CarbonImmutable $termina): bool
    {
        $zona = (string) ($sucursal->zona_horaria ?? config('app.timezone', 'UTC'));
        $desde = $inicia->setTimezone($zona);
        $hasta = $termina->setTimezone($zona);

        // Una cita no cruza la medianoche local.
        if ($desde->toDateString() !== $hasta->toDateString() && $hasta->format('H:i') !== '00:00') {
            return false;
        }
        if (ExcepcionHorarioTenant::query()->whereDate('fecha', $desde->toDateString())->exists()) {
            return false;
        }

        $horaInicio = $desde->format('H:i:s');
        $horaFin = $hasta->toDateString() === $desde->toDateString() ? $hasta->format('H:i:s') : '24:00:00';

        return HorarioAtencionTenant::query()
            ->where('instructor_id', $instructorId)
            ->where('sucursal_id', $sucursal->getKey())
            ->where('dia_semana', $desde->isoWeekday())
            ->get()
            ->contains(static fn (HorarioAtencionTenant $v): bool => self::hora((string) $v->hora_inicio) <= $horaInicio
                && self::hora((string) $v->hora_fin) >= $horaFin);
    }

    /**
     * "9:00" / "09:00" / "09:00:00" → "09:00:00" (para comparar como texto).
     */
    private static function hora(string $valor): string
    {
        $partes = array_map('intval', explode(':', $valor)) + [0, 0, 0];

        return sprintf('%02d:%02d:%02d', $partes[0], $partes[1], $partes[2]);
    }
}
