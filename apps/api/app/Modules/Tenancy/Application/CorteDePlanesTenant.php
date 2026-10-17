<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Creditos\TipoMovimiento;
use App\Modules\Tenancy\Membresias\EstadoAcuerdo;
use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\MovimientoCreditoTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Corte de los planes de una persona (ADR 0050): por cada paquete o membresía, qué
 * incluía, qué clases extra se le sumaron, en qué clases lo usó (asistió, no
 * asistió, cancelación tardía, próximas), qué se devolvió, qué venció y qué le
 * queda. Los números salen del ledger; los usos, de sus reservas. Lo ven el alumno
 * y el equipo.
 *
 * Una membresía que se renueva por años acumula cientos de reservas y movimientos:
 * las sumas se piden agrupadas a la base y de cada plan solo viajan sus usos más
 * recientes con su total; los demás se piden por páginas ({@see usosDe()}).
 */
class CorteDePlanesTenant
{
    /** Planes más recientes que se muestran. */
    private const LIMITE = 24;

    /** Usos de cada plan que viajan con el corte (los más recientes). */
    public const USOS_EN_CORTE = 10;

    public function __construct(
        private readonly LibroMayorTenant $libro,
        private readonly FechasNegocioTenant $fechas,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function dePersona(PersonaTenant $persona): array
    {
        $planes = DerechoTenant::query()
            ->whereHas('acuerdo', fn ($q) => $q->where('persona_id', $persona->getKey()))
            ->whereNull('extra_de_id')
            ->with(['acuerdo.producto', 'ofertas', 'extras.acuerdo.producto'])
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        $ids = $planes->flatMap(fn (DerechoTenant $p) => collect([$p])->concat($p->extras))
            ->map(fn (DerechoTenant $d): int => (int) $d->getKey())->values()->all();
        $sumas = $this->sumas($ids);
        $hoy = $this->hoy();

        return $planes->map(fn (DerechoTenant $plan): array => $this->corte($plan, $hoy, $sumas))->values()->all();
    }

    /**
     * Los usos de un plan (y de sus clases extra) por páginas, del más reciente al más
     * antiguo, con las próximas reservas primero.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, ultima_pagina: int}}
     */
    public function usosDe(DerechoTenant $plan, int $pagina, int $porPagina = 20): array
    {
        $ids = collect([$plan])->concat($plan->extras()->get())
            ->map(fn (DerechoTenant $d): int => (int) $d->getKey())->values()->all();
        $extras = array_values(array_filter($ids, fn (int $id): bool => $id !== (int) $plan->getKey()));
        $paginado = $this->consultaUsos($ids)->paginate($porPagina, ['*'], 'page', $pagina);

        return [
            'data' => $this->presentarUsos($paginado->getCollection(), $ids, $extras),
            'meta' => [
                'total' => $paginado->total(),
                'page' => $paginado->currentPage(),
                'per_page' => $paginado->perPage(),
                'ultima_pagina' => $paginado->lastPage(),
            ],
        ];
    }

    /** La fecha de hoy en el calendario del negocio. */
    public function hoy(): string
    {
        return $this->fechas->hoy();
    }

    /**
     * Estado efectivo de un plan (o de sus clases extra) en `$hoy`: cancelado, en
     * pausa o suspendido (por su acuerdo), por empezar, vencido, agotado o vigente.
     * Lo usan el corte y el resumen del Inicio, para que no lo deduzca cada pantalla
     * solo por el vencimiento.
     */
    public function estadoEfectivo(DerechoTenant $plan, string $hoy, int $disponibles, int $apartadas): string
    {
        $estadoAcuerdo = $plan->acuerdo?->estado;

        return match (true) {
            $estadoAcuerdo === EstadoAcuerdo::Cancelado => 'cancelado',
            $estadoAcuerdo === EstadoAcuerdo::Pausado => 'pausado',
            $estadoAcuerdo === EstadoAcuerdo::Suspendido => 'suspendido',
            $plan->valido_desde !== null && $plan->valido_desde->toDateString() > $hoy => 'por_empezar',
            $plan->valido_hasta !== null && $plan->valido_hasta->toDateString() < $hoy => 'vencido',
            ! $plan->ilimitado && $disponibles <= 0 && $apartadas <= 0 => 'agotado',
            default => 'vigente',
        };
    }

    /**
     * @param  array<int, array<string, int>>  $sumas
     * @return array<string, mixed>
     */
    private function corte(DerechoTenant $plan, string $hoy, array $sumas): array
    {
        /** @var Collection<int, DerechoTenant> $todos */
        $todos = collect([$plan])->concat($plan->extras);
        $ids = $todos->map(fn (DerechoTenant $d): int => (int) $d->getKey())->values()->all();
        $suma = function (TipoMovimiento $tipo, ?array $de = null) use ($sumas, $ids): int {
            $total = 0;
            foreach ($de ?? $ids as $id) {
                $total += $sumas[$id][$tipo->value] ?? 0;
            }

            return $total;
        };

        $disponibles = $todos->sum(fn (DerechoTenant $d): int => $this->libro->disponible($d));
        $apartadas = $todos->sum(fn (DerechoTenant $d): int => $this->libro->saldo($d) - $this->libro->disponible($d));
        $unidades = [
            'incluidas' => $suma(TipoMovimiento::Concesion, [(int) $plan->getKey()]),
            // Las clases extra (compradas aparte) y los créditos adicionales del negocio.
            'extras' => $suma(TipoMovimiento::AddOn),
            'usadas' => -$suma(TipoMovimiento::Consumo),
            'devueltas' => $suma(TipoMovimiento::Reverso),
            'vencidas' => -$suma(TipoMovimiento::Expiracion),
            'ajustes' => $suma(TipoMovimiento::Ajuste),
            'apartadas' => (int) $apartadas,
            'disponibles' => (int) $disponibles,
        ];

        $acuerdo = $plan->acuerdo;
        $extrasIds = array_values(array_filter($ids, fn (int $id): bool => $id !== (int) $plan->getKey()));
        $consultaUsos = $this->consultaUsos($ids);

        return [
            'id' => $acuerdo?->ulid,
            'derecho_id' => $plan->ulid,
            'producto' => $acuerdo?->producto?->nombre,
            ...$plan->coberturaSucursales(),
            'tipo' => $acuerdo?->producto?->tipo->value,
            'comprado' => $acuerdo?->fecha_inicio?->toDateString(),
            // Se renueva (membresía): se puede dar de baja (B04). Su permanencia, lo
            // pactado al contratar y su baja programada, si tiene.
            'recurrente' => $acuerdo?->dia_ancla !== null,
            'proximo_cobro' => $acuerdo?->proxima_cobro_en?->toDateString(),
            'permanencia_hasta' => $acuerdo?->permanencia_hasta?->toDateString(),
            'condiciones' => is_string($acuerdo?->condiciones['texto'] ?? null) ? $acuerdo->condiciones['texto'] : null,
            'baja_efectiva_el' => $acuerdo?->baja_efectiva_el?->toDateString(),
            'desde' => $plan->valido_desde?->toDateString(),
            'hasta' => $plan->valido_hasta?->toDateString(),
            'estado' => $this->estadoEfectivo($plan, $hoy, $unidades['disponibles'], $unidades['apartadas']),
            'ilimitado' => $plan->ilimitado,
            'aplica_a' => $plan->ofertas->map(fn (OfertaTenant $o): string => (string) $o->nombre)->values()->all(),
            'unidades' => $unidades,
            'extras' => $plan->extras->map(fn (DerechoTenant $extra): array => [
                'producto' => $extra->acuerdo?->producto?->nombre,
                'comprado' => $extra->acuerdo?->fecha_inicio?->toDateString(),
                'unidades' => $suma(TipoMovimiento::AddOn, [(int) $extra->getKey()]),
                'usadas' => -$suma(TipoMovimiento::Consumo, [(int) $extra->getKey()]),
            ])->values()->all(),
            // Los más recientes; el resto, por páginas en `…/planes/{derecho}/usos`.
            'usos' => $this->presentarUsos((clone $consultaUsos)->limit(self::USOS_EN_CORTE)->get(), $ids, $extrasIds),
            'usos_total' => (clone $consultaUsos)->toBase()->getCountForPagination(),
        ];
    }

    /**
     * Unidades del libro por derecho y tipo, sumadas en la base.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, int>>
     */
    private function sumas(array $ids): array
    {
        $sumas = [];
        if ($ids === []) {
            return $sumas;
        }
        MovimientoCreditoTenant::query()->toBase()
            ->whereIn('derecho_id', $ids)
            ->selectRaw('derecho_id, tipo, SUM(unidades) as total')
            ->groupBy('derecho_id', 'tipo')
            ->get()
            ->each(function (object $fila) use (&$sumas): void {
                /** @var object{derecho_id: int|string, tipo: string, total: int|string} $fila */
                $sumas[(int) $fila->derecho_id][(string) $fila->tipo] = (int) $fila->total;
            });

        return $sumas;
    }

    /**
     * Las reservas en que se usó el plan: las tomadas, las próximas y las canceladas
     * que sí cobraron (cancelación tardía: consumo neto). Las canceladas a tiempo no.
     *
     * @param  list<int>  $ids
     * @return Builder<ReservaTenant>
     */
    private function consultaUsos(array $ids): Builder
    {
        $cobradas = MovimientoCreditoTenant::query()->toBase()
            ->whereIn('derecho_id', $ids)
            ->where('referencia_tipo', 'reserva')
            ->whereIn('tipo', [TipoMovimiento::Consumo->value, TipoMovimiento::Reverso->value])
            ->groupBy('referencia_id')
            ->havingRaw('SUM(unidades) < 0')
            ->select('referencia_id');

        return ReservaTenant::query()
            ->select('reservas.*')
            ->join('sesiones', 'sesiones.id', '=', 'reservas.sesion_id')
            ->whereIn('reservas.derecho_id', $ids)
            ->where(fn (Builder $q) => $q
                ->whereIn('reservas.estado', [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value])
                ->orWhere(fn (Builder $c) => $c->where('reservas.estado', EstadoReserva::Cancelada->value)->whereIn('reservas.ulid', $cobradas)))
            ->orderByDesc('sesiones.inicia_en')
            ->orderByDesc('reservas.id');
    }

    /**
     * @param  EloquentCollection<int, ReservaTenant>  $reservas
     * @param  list<int>  $ids
     * @param  list<int>  $extras
     * @return list<array<string, mixed>>
     */
    private function presentarUsos(EloquentCollection $reservas, array $ids, array $extras): array
    {
        if ($reservas->isEmpty()) {
            return [];
        }
        $reservas->loadMissing(['sesion.oferta', 'asistencia']);
        // Unidades cobradas por reserva (consumos menos devoluciones), solo de estas.
        $cobrado = MovimientoCreditoTenant::query()->toBase()
            ->whereIn('derecho_id', $ids)
            ->where('referencia_tipo', 'reserva')
            ->whereIn('referencia_id', $reservas->map(fn (ReservaTenant $r): string => (string) $r->ulid)->all())
            ->whereIn('tipo', [TipoMovimiento::Consumo->value, TipoMovimiento::Reverso->value])
            ->groupBy('referencia_id')
            ->selectRaw('referencia_id, SUM(unidades) as total')
            ->pluck('total', 'referencia_id')
            ->map(fn ($total): int => -(int) $total);
        $ahora = CarbonImmutable::now();

        return $reservas->map(function (ReservaTenant $r) use ($cobrado, $extras, $ahora): ?array {
            $sesion = $r->sesion;
            if ($sesion === null) {
                return null;
            }
            $unidades = (int) ($cobrado->get((string) $r->ulid) ?? 0);
            $estado = match (true) {
                $r->estado === EstadoReserva::Cancelada => 'cancelacion_tardia',
                $r->asistencia?->estado?->value === 'presente' => 'asistio',
                $r->asistencia?->estado?->value === 'ausente' => 'no_asistio',
                $sesion->inicia_en->greaterThan($ahora) => 'proxima',
                default => 'tomada',
            };

            return [
                'clase' => $sesion->oferta?->nombre,
                'inicia_en' => $sesion->inicia_en->toIso8601String(),
                'zona_horaria' => $sesion->zona_horaria,
                'estado' => $estado,
                'unidades' => $unidades,
                'extra' => in_array((int) $r->derecho_id, $extras, true),
            ];
        })->filter()->values()->all();
    }
}
