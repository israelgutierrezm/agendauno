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
use Illuminate\Support\Collection;

/**
 * Corte de los planes de una persona (ADR 0050): por cada paquete o membresía, qué
 * incluía, qué clases extra se le sumaron, en qué clases lo usó (asistió, no
 * asistió, cancelación tardía, próximas), qué se devolvió, qué venció y qué le
 * queda. Los números salen del ledger; los usos, de sus reservas. Lo ven el alumno
 * y el equipo.
 */
class CorteDePlanesTenant
{
    /** Planes más recientes que se muestran. */
    private const LIMITE = 24;

    public function __construct(
        private readonly LibroMayorTenant $libro,
        private readonly MembresiasTenant $membresias,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function dePersona(PersonaTenant $persona): array
    {
        $planes = DerechoTenant::query()
            ->whereHas('acuerdo', fn ($q) => $q->where('persona_id', $persona->getKey()))
            ->whereNull('extra_de_id')
            ->with(['acuerdo.producto', 'ofertas', 'movimientos', 'extras.acuerdo.producto', 'extras.movimientos'])
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get();

        $hoy = CarbonImmutable::now($this->membresias->zona())->toDateString();

        return $planes->map(fn (DerechoTenant $plan): array => $this->corte($plan, $hoy))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function corte(DerechoTenant $plan, string $hoy): array
    {
        /** @var Collection<int, DerechoTenant> $todos */
        $todos = collect([$plan])->concat($plan->extras);
        $movimientos = $todos->flatMap(fn (DerechoTenant $d) => $d->movimientos);
        $suma = fn (TipoMovimiento $tipo, ?Collection $de = null): int => (int) ($de ?? $movimientos)
            ->filter(fn (MovimientoCreditoTenant $m): bool => $m->tipo === $tipo)
            ->sum('unidades');

        $disponibles = $todos->sum(fn (DerechoTenant $d): int => $this->libro->disponible($d));
        $apartadas = $todos->sum(fn (DerechoTenant $d): int => $this->libro->saldo($d) - $this->libro->disponible($d));
        $unidades = [
            'incluidas' => $suma(TipoMovimiento::Concesion, $plan->movimientos),
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

        return [
            'id' => $acuerdo?->ulid,
            'derecho_id' => $plan->ulid,
            'producto' => $acuerdo?->producto?->nombre,
            'tipo' => $acuerdo?->producto?->tipo->value,
            'comprado' => $acuerdo?->fecha_inicio?->toDateString(),
            'desde' => $plan->valido_desde?->toDateString(),
            'hasta' => $plan->valido_hasta?->toDateString(),
            'estado' => $this->estado($plan, $hoy, $unidades),
            'ilimitado' => $plan->ilimitado,
            'aplica_a' => $plan->ofertas->map(fn (OfertaTenant $o): string => (string) $o->nombre)->values()->all(),
            'unidades' => $unidades,
            'extras' => $plan->extras->map(fn (DerechoTenant $extra): array => [
                'producto' => $extra->acuerdo?->producto?->nombre,
                'comprado' => $extra->acuerdo?->fecha_inicio?->toDateString(),
                'unidades' => $suma(TipoMovimiento::AddOn, $extra->movimientos),
                'usadas' => -$suma(TipoMovimiento::Consumo, $extra->movimientos),
            ])->values()->all(),
            'usos' => $this->usos($todos, $movimientos),
        ];
    }

    /**
     * @param  array<string, int>  $unidades
     */
    private function estado(DerechoTenant $plan, string $hoy, array $unidades): string
    {
        $estadoAcuerdo = $plan->acuerdo?->estado;

        return match (true) {
            $estadoAcuerdo === EstadoAcuerdo::Cancelado => 'cancelado',
            $estadoAcuerdo === EstadoAcuerdo::Pausado => 'pausado',
            $estadoAcuerdo === EstadoAcuerdo::Suspendido => 'suspendido',
            $plan->valido_desde !== null && $plan->valido_desde->toDateString() > $hoy => 'por_empezar',
            $plan->valido_hasta !== null && $plan->valido_hasta->toDateString() < $hoy => 'vencido',
            ! $plan->ilimitado && $unidades['disponibles'] <= 0 && $unidades['apartadas'] <= 0 => 'agotado',
            default => 'vigente',
        };
    }

    /**
     * En qué clases se usó el plan (y sus extras): lo que ya pasó con su asistencia,
     * las cancelaciones que sí cobraron y las próximas reservas.
     *
     * @param  Collection<int, DerechoTenant>  $derechos
     * @param  Collection<int, MovimientoCreditoTenant>  $movimientos
     * @return list<array<string, mixed>>
     */
    private function usos(Collection $derechos, Collection $movimientos): array
    {
        $extras = $derechos->filter(fn (DerechoTenant $d): bool => $d->extra_de_id !== null)->map(fn (DerechoTenant $d): int => (int) $d->getKey())->values()->all();
        // Unidades cobradas por reserva (consumos menos devoluciones).
        $cobrado = $movimientos
            ->filter(fn (MovimientoCreditoTenant $m): bool => $m->referencia_tipo === 'reserva'
                && in_array($m->tipo, [TipoMovimiento::Consumo, TipoMovimiento::Reverso], true))
            ->groupBy('referencia_id')
            ->map(fn (Collection $ms): int => -(int) $ms->sum('unidades'));
        $ahora = CarbonImmutable::now();

        return ReservaTenant::query()
            ->whereIn('derecho_id', $derechos->map(fn (DerechoTenant $d): int => (int) $d->getKey())->all())
            ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value, EstadoReserva::Cancelada->value])
            ->with(['sesion.oferta', 'asistencia'])
            ->get()
            ->map(function (ReservaTenant $r) use ($cobrado, $extras, $ahora): ?array {
                $sesion = $r->sesion;
                if ($sesion === null) {
                    return null;
                }
                $unidades = (int) ($cobrado->get((string) $r->ulid) ?? 0);
                $estado = match (true) {
                    $r->estado === EstadoReserva::Cancelada => $unidades > 0 ? 'cancelacion_tardia' : null,
                    $r->asistencia?->estado?->value === 'presente' => 'asistio',
                    $r->asistencia?->estado?->value === 'ausente' => 'no_asistio',
                    $sesion->inicia_en->greaterThan($ahora) => 'proxima',
                    default => 'tomada',
                };
                if ($estado === null) {
                    return null; // Cancelada a tiempo: no se usó.
                }

                return [
                    'clase' => $sesion->oferta?->nombre,
                    'inicia_en' => $sesion->inicia_en->toIso8601String(),
                    'zona_horaria' => $sesion->zona_horaria,
                    'estado' => $estado,
                    'unidades' => $unidades,
                    'extra' => in_array((int) $r->derecho_id, $extras, true),
                ];
            })
            ->filter()
            ->sortBy('inicia_en')
            ->values()
            ->all();
    }
}
