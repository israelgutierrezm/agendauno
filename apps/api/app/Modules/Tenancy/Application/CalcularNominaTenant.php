<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\AsignacionSesionTenant;
use App\Modules\Tenancy\Models\AsistenciaTenant;
use App\Modules\Tenancy\Models\EsquemaPagoTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Nomina\TipoPago;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Calcula la nomina del staff en un periodo (R17): por cada esquema de pago activo,
 * toma las sesiones (no canceladas) que el staff trabajo en el rango y aplica su tipo:
 * por clase (numero de sesiones), por asistente (presentes) o por hora (horas de
 * clase). Trabajo una sesion quien es su profesional o esta asignado como personal,
 * salvo que un sustituto lo reemplace ({@see PersonalDeSesionTenant}, ADR 0081).
 * Opera sobre la BD del tenant resuelto.
 */
class CalcularNominaTenant
{
    /**
     * @return list<array{usuario: string|null, tipo: string, unidades: float, monto_total_minor: int, moneda: string}>
     */
    public function calcular(string $desde, string $hasta): array
    {
        $inicio = CarbonImmutable::parse($desde)->startOfDay();
        $fin = CarbonImmutable::parse($hasta)->endOfDay();

        return EsquemaPagoTenant::query()
            ->where('activo', true)
            ->with('usuario')
            ->get()
            ->map(function (EsquemaPagoTenant $esquema) use ($inicio, $fin): array {
                $sesiones = $this->sesionesDe((int) $esquema->usuario_id, $inicio, $fin);

                [$unidades, $total] = $this->calcularMonto($esquema, $sesiones);

                return [
                    'usuario' => $esquema->usuario?->name,
                    'tipo' => $esquema->tipo->value,
                    'unidades' => $unidades,
                    'monto_total_minor' => $total,
                    'moneda' => $esquema->moneda,
                ];
            })
            ->all();
    }

    /**
     * @return Collection<int, SesionTenant>
     */
    private function sesionesDe(int $usuarioId, CarbonImmutable $inicio, CarbonImmutable $fin): Collection
    {
        $sesiones = SesionTenant::query()
            ->whereBetween('inicia_en', [$inicio, $fin])
            ->where('estado', '!=', EstadoSesionTenant::Cancelada->value)
            ->where(fn ($q) => $q
                ->where('instructor_id', $usuarioId)
                ->orWhereIn('id', AsignacionSesionTenant::query()->where('usuario_id', $usuarioId)->select('sesion_id')))
            ->get();
        $asignaciones = AsignacionSesionTenant::query()->whereIn('sesion_id', $sesiones->pluck('id'))->get()->groupBy('sesion_id');

        return $sesiones
            ->filter(fn (SesionTenant $s): bool => array_key_exists(
                $usuarioId,
                PersonalDeSesionTenant::participantes($s, $asignaciones->get($s->id) ?? collect()),
            ))
            ->values();
    }

    /**
     * @param  Collection<int, SesionTenant>  $sesiones
     * @return array{0: float, 1: int} [unidades, monto_total_minor]
     */
    private function calcularMonto(EsquemaPagoTenant $esquema, Collection $sesiones): array
    {
        $monto = (int) $esquema->monto_minor;

        return match ($esquema->tipo) {
            TipoPago::PorClase => [(float) $sesiones->count(), $monto * $sesiones->count()],
            TipoPago::PorAsistente => (function () use ($monto, $sesiones): array {
                $presentes = $this->presentes($sesiones->pluck('id')->all());

                return [(float) $presentes, $monto * $presentes];
            })(),
            TipoPago::PorHora => (function () use ($monto, $sesiones): array {
                $minutos = (int) $sesiones->sum(fn (SesionTenant $s): int => (int) $s->inicia_en->diffInMinutes($s->termina_en));

                return [round($minutos / 60, 2), intdiv($monto * $minutos, 60)];
            })(),
        };
    }

    /**
     * @param  list<int>  $sesionIds
     */
    private function presentes(array $sesionIds): int
    {
        if ($sesionIds === []) {
            return 0;
        }

        return AsistenciaTenant::query()
            ->join('reservas', 'asistencias.reserva_id', '=', 'reservas.id')
            ->whereIn('reservas.sesion_id', $sesionIds)
            ->where('asistencias.estado', EstadoAsistencia::Presente->value)
            ->count();
    }
}
