<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\PlantillaHorarioTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\Reservas\Exceptions\ReservaYaAtendida;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cancelar varias fechas de una vez (ADR 0223): las de un día que el negocio cierra o
 * las de una clase recurrente desde una fecha («esta y las siguientes»). Cada una se
 * cancela como si se cancelara sola ({@see ReservasTenant::cancelarSesion}): devuelve
 * los créditos y avisa a cada persona. La que ya tiene asistencia se conserva y se dice.
 */
class CancelarFechasTenant
{
    /** Reservas que ocupan o esperan lugar. */
    private const ACTIVAS = [
        EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value,
        EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value,
    ];

    public function __construct(private readonly ReservasTenant $reservas) {}

    /**
     * Las programadas por venir de ese día, en la zona de cada sede.
     *
     * @return Collection<int, SesionTenant>
     */
    public function delDia(string $fecha): Collection
    {
        $dia = CarbonImmutable::parse($fecha);

        return SesionTenant::query()
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('inicia_en', '>', now())
            // Un margen de un día a cada lado cubre cualquier zona; luego se filtra por la suya.
            ->whereBetween('inicia_en', [$dia->subDay()->startOfDay(), $dia->addDays(2)->startOfDay()])
            ->orderBy('inicia_en')
            ->get()
            ->filter(static fn (SesionTenant $s): bool => CarbonImmutable::instance($s->inicia_en)
                ->setTimezone((string) ($s->zona_horaria ?: 'UTC'))->toDateString() === $fecha)
            ->values();
    }

    /**
     * Las programadas por venir de una clase recurrente desde esa fecha.
     *
     * @return Collection<int, SesionTenant>
     */
    public function deLaSerieDesde(PlantillaHorarioTenant $serie, string $desde): Collection
    {
        return SesionTenant::query()
            ->where('serie_id', $serie->getKey())
            ->where('estado', EstadoSesionTenant::Programada->value)
            ->where('inicia_en', '>', now())
            ->orderBy('inicia_en')
            ->get()
            ->filter(static fn (SesionTenant $s): bool => ($s->fecha_serie?->toDateString()
                ?? CarbonImmutable::instance($s->inicia_en)->setTimezone((string) ($s->zona_horaria ?: 'UTC'))->toDateString()) >= $desde)
            ->values();
    }

    /**
     * Cuántas fechas y cuántas personas con lugar o en espera.
     *
     * @param  Collection<int, SesionTenant>  $sesiones
     * @return array{sesiones: int, reservas: int}
     */
    public function resumen(Collection $sesiones): array
    {
        return [
            'sesiones' => $sesiones->count(),
            'reservas' => $sesiones->isEmpty() ? 0 : ReservaTenant::query()
                ->whereIn('sesion_id', $sesiones->map(static fn (SesionTenant $s): int => (int) $s->getKey())->all())
                ->whereIn('estado', self::ACTIVAS)
                ->count(),
        ];
    }

    /**
     * Las cancela una por una. Devuelve cuántas y las que se conservaron, con su motivo.
     *
     * @param  Collection<int, SesionTenant>  $sesiones
     * @return array{canceladas: int, conservadas: list<array{fecha: string, motivo: string}>}
     */
    public function cancelar(Collection $sesiones, ?Usuario $actor): array
    {
        $canceladas = 0;
        $conservadas = [];
        foreach ($sesiones as $sesion) {
            try {
                $this->reservas->cancelarSesion($sesion, $actor);
                $canceladas++;
            } catch (ReservaYaAtendida $e) {
                $conservadas[] = [
                    'fecha' => CarbonImmutable::instance($sesion->inicia_en)->setTimezone((string) ($sesion->zona_horaria ?: 'UTC'))->toDateString(),
                    'motivo' => $e->getMessage(),
                ];
            }
        }

        return ['canceladas' => $canceladas, 'conservadas' => $conservadas];
    }
}
