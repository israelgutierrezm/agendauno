<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que el negocio tiene por cobrar, con un solo criterio para el Inicio y para
 * «Por cobrar»: las órdenes pendientes que ya se deben (compras sin pagar y citas o
 * clases de pago que ya empezaron). Una cita próxima que se paga al atenderla
 * todavía no es un adeudo: se cuenta aparte (`proximas`). Una renovación cuya orden
 * nació con el aviso sigue en la lista (se puede cobrar antes), con su vencimiento, y se
 * cuenta aparte mientras no vence (`renovaciones_por_vencer`). Con alcance por sucursal
 * (R19): el personal acotado solo ve lo de sus sedes.
 */
class PorCobrarTenant
{
    public function __construct(private readonly FechasNegocioTenant $fechas) {}

    /**
     * Las órdenes que ya se deben, de la más antigua a la más reciente.
     *
     * @param  list<int>|null  $permitidas
     * @return Builder<OrdenTenant>
     */
    public function vencidas(?array $permitidas): Builder
    {
        $ahora = CarbonImmutable::now();

        return $this->pendientes($permitidas)
            ->where(fn (Builder $q) => $q->whereNull('sesion_id')
                ->orWhereHas('sesion', fn (Builder $s) => $s->where('inicia_en', '<=', $ahora)))
            // Una cita a la que no vino no se cobra en caja (ADR 0166): ni la del anticipo
            // ni la del resto.
            ->whereNotExists(fn ($q) => $q->from('reservas')
                ->join('asistencias', 'asistencias.reserva_id', '=', 'reservas.id')
                ->where('asistencias.estado', EstadoAsistencia::Ausente->value)
                ->where(fn ($w) => $w->whereColumn('reservas.orden_id', 'ordenes.id')->orWhereColumn('reservas.saldo_orden_id', 'ordenes.id')))
            ->orderBy('id');
    }

    /**
     * Cuántas órdenes y cuánto se debe (por moneda), cuántas citas próximas se
     * cobrarán al atenderlas y cuántas de las órdenes son renovaciones que aún no vencen
     * (la orden nace días antes, con el aviso: ya se puede cobrar, pero aún no es un
     * atraso).
     *
     * @param  list<int>|null  $permitidas
     * @return array{ordenes_pendientes: int, por_cobrar: list<array{moneda: string, total_minor: int}>, proximas: int, renovaciones_por_vencer: int}
     */
    public function resumen(?array $permitidas): array
    {
        $porMoneda = $this->vencidas($permitidas)
            ->reorder()
            ->groupBy('moneda')
            ->selectRaw('moneda, COUNT(*) as n, SUM(total_minor) as total')
            ->toBase()
            ->get();

        return [
            'ordenes_pendientes' => (int) $porMoneda->sum(fn (object $f): int => (int) $f->n),
            'por_cobrar' => $porMoneda->map(fn (object $f): array => [
                'moneda' => mb_strtoupper((string) $f->moneda),
                'total_minor' => (int) $f->total,
            ])->values()->all(),
            'proximas' => $this->pendientes($permitidas)
                ->whereHas('sesion', fn (Builder $s) => $s->where('inicia_en', '>', CarbonImmutable::now()))
                ->count(),
            'renovaciones_por_vencer' => $this->vencidas($permitidas)
                ->reorder()
                ->whereDate('renueva_vence_el', '>=', $this->fechas->hoy())
                ->count(),
        ];
    }

    /**
     * Si la orden es una renovación: cuándo vence lo que renueva y cómo va con hoy del
     * negocio (`proxima`, `hoy` o `vencida`). Null si no lo es.
     *
     * @return array{vence_el: string, situacion: string}|null
     */
    public function renovacion(OrdenTenant $orden): ?array
    {
        if ($orden->renueva_vence_el === null) {
            return null;
        }
        $vence = $orden->renueva_vence_el->toDateString();
        $hoy = $this->fechas->hoy();

        return [
            'vence_el' => $vence,
            'situacion' => match (true) {
                $vence > $hoy => 'proxima',
                $vence === $hoy => 'hoy',
                default => 'vencida',
            },
        ];
    }

    /**
     * @param  list<int>|null  $permitidas
     * @return Builder<OrdenTenant>
     */
    private function pendientes(?array $permitidas): Builder
    {
        return OrdenTenant::query()
            ->where('estado', EstadoOrden::Pendiente->value)
            ->when($permitidas !== null, fn (Builder $q) => $q->whereIn('sucursal_id', $permitidas));
    }
}
