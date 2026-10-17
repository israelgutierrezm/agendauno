<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\AsignacionSesionTenant;
use App\Modules\Tenancy\Models\AsistenciaTenant;
use App\Modules\Tenancy\Models\EsquemaPagoTenant;
use App\Modules\Tenancy\Models\EsquemaPagoVersionTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Nomina\TipoPago;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calcula la nomina del staff en un periodo (R17): por cada esquema de pago activo,
 * toma las sesiones (no canceladas) que el staff trabajo en el rango y aplica su tipo:
 * por clase (numero de sesiones), por asistente (presentes) o por hora (horas de
 * clase). Trabajo una sesion quien es su profesional o esta asignado como personal,
 * salvo que un sustituto lo reemplace ({@see PersonalDeSesionTenant}, ADR 0081).
 * Opera sobre la BD del tenant resuelto.
 *
 * Para pagar (ADR 0153):
 * - El periodo son días del negocio, en su zona horaria.
 * - Lo devengado (`monto_total_minor`) es solo lo ya impartido (la sesión terminó). Lo
 *   que falta del periodo va aparte como proyección (`por_venir_minor`; por asistente,
 *   con las reservas confirmadas) y `al_cierre_minor` suma los dos.
 * - Cada sesión se paga con la tarifa vigente el día en que fue
 *   ({@see TarifasDePagoTenant}): si la tarifa cambió dentro del periodo, la persona
 *   sale en una fila por tarifa.
 */
class CalcularNominaTenant
{
    public function __construct(
        private readonly TarifasDePagoTenant $tarifas,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    /**
     * @return list<array{usuario_id: string|null, usuario: string|null, tipo: string, monto_minor: int, moneda: string, vigente_desde: string|null, unidades: float, monto_total_minor: int, unidades_por_venir: float, por_venir_minor: int, al_cierre_minor: int}>
     */
    public function calcular(string $desde, string $hasta): array
    {
        $inicio = $this->fechas->inicioDelDia($desde);
        $fin = $this->fechas->finDelDia($hasta);
        $ahora = CarbonImmutable::now();

        $filas = [];
        foreach (EsquemaPagoTenant::query()->where('activo', true)->with('usuario')->orderBy('id')->get() as $esquema) {
            $usuarioId = (int) $esquema->usuario_id;

            /** @var array<int, array{version: EsquemaPagoVersionTenant, hechas: Collection<int, SesionTenant>, por_venir: Collection<int, SesionTenant>}> $grupos */
            $grupos = [];
            foreach ($this->sesionesDe($usuarioId, $inicio, $fin) as $sesion) {
                $version = $this->tarifas->para($usuarioId, $sesion->inicia_en);
                if ($version === null) {
                    // Su tarifa empieza después: esa sesión no se le paga con este esquema.
                    continue;
                }
                $clave = spl_object_id($version);
                $grupos[$clave] ??= ['version' => $version, 'hechas' => collect(), 'por_venir' => collect()];
                $grupos[$clave][$sesion->termina_en->lte($ahora) ? 'hechas' : 'por_venir']->push($sesion);
            }
            // Sin sesiones en el periodo también sale, en cero, con la tarifa de entonces.
            if ($grupos === []) {
                $version = $this->tarifas->para($usuarioId, $fin) ?? new EsquemaPagoVersionTenant([
                    'usuario_id' => $usuarioId, 'tipo' => $esquema->tipo, 'monto_minor' => $esquema->monto_minor,
                    'moneda' => $esquema->moneda, 'vigente_desde' => null,
                ]);
                $grupos[] = ['version' => $version, 'hechas' => collect(), 'por_venir' => collect()];
            }

            foreach ($grupos as $grupo) {
                $version = $grupo['version'];
                [$unidades, $devengado] = $this->monto($version, $grupo['hechas'], false);
                [$unidadesPorVenir, $porVenir] = $this->monto($version, $grupo['por_venir'], true);
                $filas[] = [
                    'usuario_id' => $esquema->usuario?->ulid,
                    'usuario' => $esquema->usuario?->name,
                    'tipo' => $version->tipo->value,
                    'monto_minor' => $version->monto_minor,
                    'moneda' => $version->moneda,
                    'vigente_desde' => $version->vigente_desde?->toDateString(),
                    'unidades' => $unidades,
                    'monto_total_minor' => $devengado,
                    'unidades_por_venir' => $unidadesPorVenir,
                    'por_venir_minor' => $porVenir,
                    'al_cierre_minor' => $devengado + $porVenir,
                ];
            }
        }

        return $filas;
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
            ->orderBy('inicia_en')
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
     * Unidades y monto de unas sesiones con una tarifa. Con `$proyectar` (las que no han
     * terminado), por asistente cuenta las reservas confirmadas en vez de los presentes.
     *
     * @param  Collection<int, SesionTenant>  $sesiones
     * @return array{0: float, 1: int} [unidades, monto_minor]
     */
    private function monto(EsquemaPagoVersionTenant $tarifa, Collection $sesiones, bool $proyectar): array
    {
        $monto = $tarifa->monto_minor;

        return match ($tarifa->tipo) {
            TipoPago::PorClase => [(float) $sesiones->count(), $monto * $sesiones->count()],
            TipoPago::PorAsistente => (function () use ($monto, $sesiones, $proyectar): array {
                $ids = $sesiones->pluck('id')->all();
                $personas = $proyectar ? $this->confirmadas($ids) : $this->presentes($ids);

                return [(float) $personas, $monto * $personas];
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

    /**
     * @param  list<int>  $sesionIds
     */
    private function confirmadas(array $sesionIds): int
    {
        if ($sesionIds === []) {
            return 0;
        }

        return DB::connection('tenant')->table('reservas')
            ->whereIn('sesion_id', $sesionIds)
            ->where('estado', EstadoReserva::Confirmada->value)
            ->count();
    }
}
