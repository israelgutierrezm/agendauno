<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Captacion\OrigenProspecto;
use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ProspectoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\TipoSesionTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * El embudo del negocio (plan de mejoras C08): de la visita a su página hasta que su
 * cliente renueva, con la cantidad y su denominador (nunca un porcentaje sin contexto).
 * Todo sale de los registros del servidor; nada de datos personales.
 *
 * - **Sitio → solicitud:** solicitudes llegadas por la página o el widget / visitas
 *   medidas. Solo eso es comparable: las de un enlace o QR, un formulario incrustado o
 *   las que registra el equipo no pasan por una visita medida y van aparte (`otras`).
 * - **Solicitud → primera compra:** de esas solicitudes, cuántas se volvieron clientes con
 *   un pago confirmado.
 * - **Compra → primera asistencia:** de quienes compraron por primera vez en el periodo,
 *   cuántos ya asistieron.
 * - **Renovación:** de las renovaciones que tocaron en el periodo, cuántas se pagaron.
 * - **Tiempo de respuesta:** de la solicitud al primer contacto registrado (mediana en
 *   horas) y cuántas siguen sin atender.
 *
 * En un negocio de citas (R11) los pasos de planes no representan su operación: sale
 * además `citas`, su propio recorrido, cada paso con su base ({@see self::citas()}).
 *
 * Fechas en la zona del negocio; un pago cuenta una vez (por orden pagada).
 */
class EmbudoNegocioTenant
{
    /** @var list<int>|null las sedes que ve quien lo pide (null = todas; ADR 0166) */
    private ?array $sedes = null;

    public function __construct(
        private readonly FechasNegocioTenant $fechas,
        private readonly VisitasPaginaTenant $visitas,
        private readonly ModalidadNegocioTenant $modalidad,
    ) {}

    /**
     * @param  list<int>|null  $sedes  las que ve quien lo pide (null = todas)
     * @return array<string, mixed>
     */
    public function calcular(string $desde, string $hasta, ?array $sedes = null): array
    {
        $this->sedes = $sedes;
        $zona = $this->fechas->zona();
        $inicio = CarbonImmutable::parse($desde, $zona)->startOfDay()->utc();
        $fin = CarbonImmutable::parse($hasta, $zona)->endOfDay()->utc();

        $solicitudes = ProspectoTenant::query()
            ->where('origen', OrigenProspecto::Formulario->value)
            ->whereBetween('created_at', [$inicio, $fin])
            ->when($sedes !== null, fn ($q) => $q->whereIn('sucursal_id', $sedes ?? []))
            ->get(['id', 'persona_id', 'convertido_en', 'created_at', 'primer_contacto_en', 'canal']);
        $porCanal = $solicitudes->countBy(static fn (ProspectoTenant $p): string => (string) ($p->canal ?? ''));
        $conVisita = array_sum(array_map(static fn (string $c): int => (int) $porCanal->get($c, 0), CaptacionTenant::CANALES_CON_VISITA));

        // De las solicitudes, quienes ya son clientes con un pago confirmado después.
        $compraron = $solicitudes->filter(fn (ProspectoTenant $p): bool => $p->persona_id !== null
            && OrdenTenant::query()
                ->where('persona_id', $p->persona_id)
                ->where('estado', EstadoOrden::Pagada->value)
                ->where('pagada_en', '>=', $p->created_at)
                ->exists())->count();

        // Tiempo de respuesta: horas de la solicitud al primer contacto.
        $horas = $solicitudes
            ->filter(fn (ProspectoTenant $p): bool => $p->primer_contacto_en !== null)
            ->map(fn (ProspectoTenant $p): float => round(abs((float) $p->created_at->diffInMinutes($p->primer_contacto_en)) / 60, 1))
            ->sort()
            ->values();

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'sitio_a_solicitud' => [
                'visitas' => $this->visitas->entre($desde, $hasta),
                'solicitudes' => $conVisita,
                // Sin comparar con las visitas: no pasan por una visita medida.
                'otras' => [
                    'enlace' => (int) $porCanal->get('enlace', 0),
                    'embebido' => (int) $porCanal->get('embebido', 0),
                    'sin_canal' => (int) $porCanal->get('', 0),
                    'equipo' => ProspectoTenant::query()
                        ->where('origen', OrigenProspecto::Manual->value)
                        ->whereBetween('created_at', [$inicio, $fin])
                        ->when($sedes !== null, fn ($q) => $q->whereIn('sucursal_id', $sedes ?? []))
                        ->count(),
                ],
            ],
            'solicitud_a_compra' => [
                'solicitudes' => $solicitudes->count(),
                'compraron' => $compraron,
            ],
            'compra_a_asistencia' => $this->compraAAsistencia($inicio, $fin),
            // Las renovaciones que tocaban en el periodo (por cuándo vencía lo que
            // renuevan; las anteriores, por cuándo se creó su orden), y cuántas se pagaron.
            'renovacion' => [
                'por_renovar' => $this->renovaciones($desde, $hasta, $inicio, $fin)->count(),
                'renovadas' => $this->renovaciones($desde, $hasta, $inicio, $fin)->where('estado', EstadoOrden::Pagada->value)->count(),
            ],
            'tiempo_respuesta' => [
                'solicitudes' => $solicitudes->count(),
                'atendidas' => $horas->count(),
                'mediana_horas' => self::mediana($horas->all()),
            ],
            // Negocio de citas: cita → asistencia → cobro → siguiente visita.
            'citas' => $this->modalidad->esCitas() ? $this->citas($inicio, $fin, $solicitudes) : null,
        ];
    }

    /**
     * El recorrido de un negocio de citas (R11), del periodo y en la zona del negocio:
     *
     * - **Interés → cita:** solicitudes del periodo y de cuántas su persona agendó
     *   después una cita confirmada.
     * - **Agendadas → confirmadas:** citas agendadas en el periodo y cuántas siguen
     *   confirmadas (no se cancelaron, no se vencieron sin pagar ni siguen apartadas).
     * - **Cita → asistencia:** citas del periodo que ya pasaron (sin las canceladas) y en
     *   cuántas llegó el cliente.
     * - **Asistencia → cobro:** de las atendidas, cuántas ya están cobradas (su orden y,
     *   con anticipo, su saldo; las de bono no deben nada).
     * - **Siguiente visita:** de los clientes atendidos en el periodo, cuántos ya tienen
     *   otra cita después.
     *
     * Cuenta la fecha de la cita (la sesión), no la del registro de su asistencia.
     *
     * @param  Collection<int, ProspectoTenant>  $solicitudes  las del periodo
     * @return array{interes: array{solicitudes: int, con_cita: int}, agendadas: array{agendadas: int, confirmadas: int}, asistencia: array{citas: int, asistieron: int}, cobro: array{atendidas: int, cobradas: int}, siguiente: array{clientes: int, volvieron: int}}
     */
    private function citas(CarbonImmutable $inicio, CarbonImmutable $fin, Collection $solicitudes): array
    {
        $sedes = $this->sedes;
        $deCitas = static fn (Builder $q) => $q->where('tipo', TipoSesionTenant::Cita->value)
            ->when($sedes !== null, fn (Builder $s) => $s->whereIn('sucursal_id', $sedes ?? []));
        // Sigue en pie: ni apartada esperando su pago, ni cancelada, ni vencida.
        $sinCaer = [EstadoReserva::PendientePago->value, EstadoReserva::Cancelada->value, EstadoReserva::Expirada->value];

        $agendadas = ReservaTenant::query()->whereHas('sesion', $deCitas)->whereBetween('created_at', [$inicio, $fin]);
        $confirmadas = (clone $agendadas)->whereNotIn('estado', $sinCaer);

        // De cada solicitud, si su persona agendó después una cita que sigue en pie.
        $conCita = $solicitudes->filter(fn (ProspectoTenant $p): bool => $p->persona_id !== null
            && ReservaTenant::query()
                ->where('persona_id', $p->persona_id)
                ->where('created_at', '>=', $p->created_at)
                ->whereNotIn('estado', $sinCaer)
                ->whereHas('sesion', $deCitas)
                ->exists())->count();

        // Las que ya pasaron en el periodo (por la hora de la cita).
        $hasta = $fin->lessThan(now()) ? $fin : now()->toImmutable();
        $pasadas = ReservaTenant::query()
            ->whereHas('sesion', fn (Builder $q) => $deCitas($q)->whereBetween('inicia_en', [$inicio, $hasta]))
            ->where('estado', '!=', EstadoReserva::Cancelada->value)
            ->where('estado', '!=', EstadoReserva::Expirada->value);
        $atendidas = (clone $pasadas)->whereHas('asistencia', fn (Builder $q) => $q->where('estado', EstadoAsistencia::Presente->value))
            ->with(['orden', 'saldoOrden', 'sesion'])
            ->get();
        $cobradas = $atendidas->filter(static fn (ReservaTenant $r): bool => $r->ordenPorCobrar() === null)->count();

        // De cada cliente atendido, su última visita del periodo: ¿tiene otra cita después?
        $ultimas = $atendidas->groupBy('persona_id')
            ->map(static fn ($suyas) => $suyas->max(static fn (ReservaTenant $r) => $r->sesion?->inicia_en));
        $volvieron = $ultimas->filter(static fn ($ultima, $persona): bool => $ultima !== null && ReservaTenant::query()
            ->where('persona_id', $persona)
            ->whereNotIn('estado', [EstadoReserva::Cancelada->value, EstadoReserva::Expirada->value])
            ->whereHas('sesion', fn (Builder $q) => $q->where('tipo', TipoSesionTenant::Cita->value)->where('inicia_en', '>', $ultima))
            ->exists())->count();

        return [
            'interes' => ['solicitudes' => $solicitudes->count(), 'con_cita' => $conCita],
            'agendadas' => ['agendadas' => $agendadas->count(), 'confirmadas' => $confirmadas->count()],
            'asistencia' => ['citas' => $pasadas->count(), 'asistieron' => $atendidas->count()],
            'cobro' => ['atendidas' => $atendidas->count(), 'cobradas' => $cobradas],
            'siguiente' => ['clientes' => $ultimas->count(), 'volvieron' => $volvieron],
        ];
    }

    /**
     * Quienes compraron un plan por primera vez en el periodo y cuántos ya asistieron.
     *
     * @return array{compradores_nuevos: int, asistieron: int}
     */
    private function compraAAsistencia(CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        // La primera compra pagada de un plan (no cuotas ni citas) de cada persona.
        $primeras = LineaOrdenTenant::query()
            ->join('ordenes', 'ordenes.id', '=', 'lineas_orden.orden_id')
            ->where('ordenes.estado', EstadoOrden::Pagada->value)
            ->whereNotNull('lineas_orden.producto_comercial_id')
            ->whereNull('ordenes.renueva_acuerdo_id')
            ->when($this->sedes !== null, fn ($q) => $q->whereIn('ordenes.sucursal_id', $this->sedes ?? []))
            ->selectRaw('COALESCE(lineas_orden.beneficiario_id, ordenes.persona_id) as pid, MIN(ordenes.pagada_en) as primera')
            ->groupByRaw('COALESCE(lineas_orden.beneficiario_id, ordenes.persona_id)')
            ->get()
            ->filter(fn ($f): bool => CarbonImmutable::parse((string) $f->getAttribute('primera'))->betweenIncluded($inicio, $fin));

        // Vino a una clase después de comprar: por la fecha de la clase, no la del
        // registro de su asistencia (R12).
        $asistieron = $primeras->filter(fn ($f): bool => ReservaTenant::query()
            ->where('persona_id', (int) $f->getAttribute('pid'))
            ->whereHas('asistencia', fn (Builder $q) => $q->where('estado', EstadoAsistencia::Presente->value))
            ->whereHas('sesion', fn (Builder $q) => $q->where('inicia_en', '>=', (string) $f->getAttribute('primera')))
            ->exists())->count();

        return ['compradores_nuevos' => $primeras->count(), 'asistieron' => $asistieron];
    }

    /**
     * Las órdenes de renovación de lo que vencía entre esos días, de las sedes que ve
     * quien lo pide.
     *
     * @return Builder<OrdenTenant>
     */
    private function renovaciones(string $desde, string $hasta, CarbonImmutable $inicio, CarbonImmutable $fin): Builder
    {
        return OrdenTenant::query()
            ->whereNotNull('renueva_acuerdo_id')
            ->where(fn (Builder $q) => $q->whereBetween('renueva_vence_el', [$desde, $hasta])
                ->orWhere(fn (Builder $antes) => $antes->whereNull('renueva_vence_el')->whereBetween('created_at', [$inicio, $fin])))
            ->when($this->sedes !== null, fn (Builder $q) => $q->whereIn('sucursal_id', $this->sedes ?? []));
    }

    /**
     * @param  list<float>  $valores  ordenados
     */
    private static function mediana(array $valores): ?float
    {
        $n = count($valores);
        if ($n === 0) {
            return null;
        }
        $medio = intdiv($n, 2);

        return $n % 2 === 1 ? $valores[$medio] : round(($valores[$medio - 1] + $valores[$medio]) / 2, 1);
    }
}
