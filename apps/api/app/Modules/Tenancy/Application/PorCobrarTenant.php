<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que el negocio tiene por cobrar, con un solo criterio para el Inicio y para
 * «Por cobrar»: las órdenes pendientes que ya se deben (compras sin pagar y citas o
 * clases de pago que ya empezaron). Una cita próxima que se paga al atenderla
 * todavía no es un adeudo: se cuenta aparte (`proximas`). Con alcance por sucursal
 * (R19): el personal acotado solo ve lo de sus sedes.
 */
class PorCobrarTenant
{
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
            ->orderBy('id');
    }

    /**
     * Cuántas órdenes y cuánto se debe (por moneda), y cuántas citas próximas se
     * cobrarán al atenderlas.
     *
     * @param  list<int>|null  $permitidas
     * @return array{ordenes_pendientes: int, por_cobrar: list<array{moneda: string, total_minor: int}>, proximas: int}
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
