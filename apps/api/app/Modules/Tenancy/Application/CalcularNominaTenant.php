<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\AsignacionSesionTenant;
use App\Modules\Tenancy\Models\AsistenciaTenant;
use App\Modules\Tenancy\Models\EsquemaPagoTenant;
use App\Modules\Tenancy\Models\EsquemaPagoVersionTenant;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\PagoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Nomina\TipoPago;
use App\Modules\Tenancy\Pagos\EstadoPago;
use App\Modules\Tenancy\Pagos\EstadoReembolso;
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
 *
 * En un negocio de citas (ADR 0219):
 * - Por porcentaje, se gana el porcentaje de lo conservado por cada cita atendida: lo
 *   pagado menos lo devuelto, lo neto de una vez del servicio pagado por adelantado o,
 *   con bono, el valor del servicio al reservar (ADR 0231). La proyección, con el precio
 *   del servicio.
 * - Las propinas cobradas con sus citas van en su propia fila (`tipo` = `propinas`), de
 *   quien la atendió, tenga o no esquema de pago.
 */
class CalcularNominaTenant
{
    public function __construct(
        private readonly TarifasDePagoTenant $tarifas,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    /**
     * @return list<array{usuario_id: string|null, usuario: string|null, tipo: string, monto_minor: int, porcentaje_bp: int|null, moneda: string, vigente_desde: string|null, unidades: float, monto_total_minor: int, unidades_por_venir: float, por_venir_minor: int, al_cierre_minor: int}>
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
                    'porcentaje_bp' => $esquema->porcentaje_bp, 'moneda' => $esquema->moneda, 'vigente_desde' => null,
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
                    'porcentaje_bp' => $version->porcentaje_bp,
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

        return [...$filas, ...$this->propinas($inicio, $fin)];
    }

    /**
     * Las propinas cobradas con las citas del periodo, por profesional (ADR 0219): de
     * las ventas que no se anularon.
     *
     * @return list<array{usuario_id: string|null, usuario: string|null, tipo: string, monto_minor: int, porcentaje_bp: int|null, moneda: string, vigente_desde: string|null, unidades: float, monto_total_minor: int, unidades_por_venir: float, por_venir_minor: int, al_cierre_minor: int}>
     */
    private function propinas(CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        $ventas = DB::connection('tenant')->table('ventas_pos')
            ->join('reservas', 'ventas_pos.reserva_id', '=', 'reservas.id')
            ->whereNull('ventas_pos.anulada_en')
            ->where('ventas_pos.propina_minor', '>', 0)
            ->whereBetween('ventas_pos.created_at', [$inicio, $fin])
            ->get(['reservas.sesion_id as sesion_id', 'ventas_pos.moneda as moneda', 'ventas_pos.propina_minor as propina']);
        $sesionIds = $ventas->pluck('sesion_id')->unique()->values()->all();
        $sesiones = SesionTenant::query()->whereKey($sesionIds)->get()->keyBy('id');
        $asignaciones = AsignacionSesionTenant::query()->whereIn('sesion_id', $sesionIds)->get()->groupBy('sesion_id');

        // De quien la atendió: el sustituto, si lo hubo (ADR 0231).
        $totales = [];
        foreach ($ventas as $venta) {
            $sesion = $sesiones->get($venta->sesion_id);
            $usuarioId = $sesion instanceof SesionTenant ? PersonalDeSesionTenant::imparte($sesion, $asignaciones->get($sesion->id) ?? collect()) : null;
            if ($usuarioId === null) {
                continue;
            }
            $clave = $usuarioId.'|'.$venta->moneda;
            $totales[$clave] ??= ['usuario' => $usuarioId, 'moneda' => (string) $venta->moneda, 'veces' => 0, 'total' => 0];
            $totales[$clave]['veces']++;
            $totales[$clave]['total'] += (int) $venta->propina;
        }
        $usuarios = Usuario::query()->withTrashed()->whereKey(array_column($totales, 'usuario'))->get()->keyBy('id');

        return collect($totales)
            ->sortBy(static fn (array $t): string => (string) $usuarios->get($t['usuario'])?->name)
            ->map(static fn (array $t): array => [
                'usuario_id' => (string) $usuarios->get($t['usuario'])?->ulid,
                'usuario' => (string) $usuarios->get($t['usuario'])?->name,
                'tipo' => 'propinas',
                'monto_minor' => 0,
                'porcentaje_bp' => null,
                'moneda' => $t['moneda'],
                'vigente_desde' => null,
                'unidades' => (float) $t['veces'],
                'monto_total_minor' => $t['total'],
                'unidades_por_venir' => 0.0,
                'por_venir_minor' => 0,
                'al_cierre_minor' => $t['total'],
            ])->values()->all();
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
            TipoPago::PorcentajeVenta => [
                (float) $sesiones->count(),
                intdiv($this->cobradoDeCitas($sesiones->pluck('id')->all(), $proyectar) * (int) ($tarifa->porcentaje_bp ?? 0), 10000),
            ],
        };
    }

    /**
     * Lo conservado por esas citas (ADR 0219, 0231), sobre lo que se calcula la comisión:
     * solo de quien llegó (de una inasistencia, lo conservado es del negocio). Lo pagado
     * menos lo devuelto de sus órdenes (la del servicio y la del saldo de un anticipo); lo
     * neto de una vez del servicio pagado por adelantado o, si se tomó con su bono, el
     * valor del servicio al reservar. Con `$proyectar`, el precio del servicio de las
     * reservas confirmadas.
     *
     * @param  list<int>  $sesionIds
     */
    private function cobradoDeCitas(array $sesionIds, bool $proyectar): int
    {
        if ($sesionIds === []) {
            return 0;
        }
        $reservas = ReservaTenant::query()
            ->whereIn('sesion_id', $sesionIds)
            ->where('estado', EstadoReserva::Confirmada->value)
            ->with(['sesion.oferta', 'asistencia', 'lineaOrden.orden.lineas'])
            ->get();

        return (int) $reservas->sum(function (ReservaTenant $r) use ($proyectar): int {
            $precio = (int) ($r->sesion?->oferta->precio_clase_minor ?? 0);
            if ($proyectar) {
                return $precio;
            }
            if ($r->asistencia?->estado !== EstadoAsistencia::Presente) {
                return 0;
            }
            $pagado = 0;
            foreach (array_filter([$r->orden_id, $r->saldo_orden_id]) as $ordenId) {
                $pagado += $this->netoDeOrden((int) $ordenId);
            }
            if ($pagado > 0) {
                return $pagado;
            }
            if ($r->lineaOrden instanceof LineaOrdenTenant) {
                return $this->netoDeUnaVez($r->lineaOrden);
            }

            return $r->derecho_id !== null ? (int) ($r->valor_servicio_minor ?? $precio) : 0;
        });
    }

    /** Lo aprobado de una orden menos lo ya devuelto. */
    private function netoDeOrden(int $ordenId): int
    {
        return (int) PagoTenant::query()
            ->where('orden_id', $ordenId)
            ->whereIn('estado', [EstadoPago::Aprobado->value, EstadoPago::ParcialmenteReembolsado->value, EstadoPago::Reembolsado->value])
            ->withSum(['reembolsos as devuelto_minor' => static fn ($q) => $q->where('estado', EstadoReembolso::Aprobado->value)], 'monto_minor')
            ->get()
            ->sum(static fn (PagoTenant $p): int => max(0, (int) $p->monto_minor - (int) $p->getAttribute('devuelto_minor')));
    }

    /**
     * Una vez de un servicio prepagado: lo neto de su orden, en la parte de ese concepto
     * (con los descuentos y devoluciones de la orden) y entre las veces compradas.
     */
    private function netoDeUnaVez(LineaOrdenTenant $linea): int
    {
        $orden = $linea->orden;
        if (! $orden instanceof OrdenTenant) {
            return 0;
        }
        $subtotal = (int) $orden->lineas->sum('subtotal_minor');
        if ($subtotal <= 0) {
            return 0;
        }
        $delConcepto = intdiv($this->netoDeOrden((int) $orden->getKey()) * (int) $linea->subtotal_minor, $subtotal);

        return intdiv($delConcepto, max(1, (int) $linea->cantidad));
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
