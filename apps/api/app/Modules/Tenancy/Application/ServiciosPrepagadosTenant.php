<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\LineaOrdenTenant;
use App\Modules\Tenancy\Models\OfertaTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Reservas\EstadoReserva;

/**
 * Servicios que la persona ya pagó por adelantado (ADR 0218): conceptos de una orden
 * pagada que son un servicio del catálogo (p. ej. un presupuesto convertido) y que aún
 * no se usan en una cita. Una cita de ese servicio se agenda con uno de ellos en lugar
 * de cobrarse de nuevo. Cada concepto se usa hasta su cantidad; una cita cancelada lo
 * libera.
 */
class ServiciosPrepagadosTenant
{
    /** Las reservas que lo usan (las canceladas o vencidas no). */
    private const ESTADOS_QUE_USAN = [
        EstadoReserva::Confirmada, EstadoReserva::PendientePago, EstadoReserva::Ofrecida, EstadoReserva::EnEspera,
    ];

    /**
     * El primer concepto pagado de ese servicio que aún le queda, o null. Con
     * `$bloquear`, bajo candado (dentro de la transacción que agenda): dos citas a la
     * vez no usan la misma vez.
     */
    public function disponible(PersonaTenant $persona, OfertaTenant $oferta, bool $bloquear = false): ?LineaOrdenTenant
    {
        foreach ($this->lineas($persona, $oferta, $bloquear) as $linea) {
            if ($this->usadas($linea) < (int) $linea->cantidad) {
                return $linea;
            }
        }

        return null;
    }

    /** Cuántas veces le quedan, de ese servicio, pagadas por adelantado. */
    public function restantes(PersonaTenant $persona, OfertaTenant $oferta): int
    {
        $total = 0;
        foreach ($this->lineas($persona, $oferta, false) as $linea) {
            $total += max(0, (int) $linea->cantidad - $this->usadas($linea));
        }

        return $total;
    }

    /**
     * @return iterable<LineaOrdenTenant>
     */
    private function lineas(PersonaTenant $persona, OfertaTenant $oferta, bool $bloquear): iterable
    {
        return LineaOrdenTenant::query()
            ->where('oferta_id', $oferta->getKey())
            ->whereHas('orden', static fn ($q) => $q
                ->where('persona_id', $persona->getKey())
                ->where('estado', EstadoOrden::Pagada->value))
            ->orderBy('id')
            ->when($bloquear, static fn ($q) => $q->lockForUpdate())
            ->get();
    }

    private function usadas(LineaOrdenTenant $linea): int
    {
        return ReservaTenant::query()
            ->where('linea_orden_id', $linea->getKey())
            ->whereIn('estado', array_map(static fn (EstadoReserva $e): string => $e->value, self::ESTADOS_QUE_USAN))
            ->count();
    }
}
