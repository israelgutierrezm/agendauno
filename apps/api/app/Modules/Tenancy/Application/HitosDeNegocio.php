<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\OrdenTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Ordenes\EstadoOrden;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use App\Modules\Tenancy\TipoPersonaTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * La primera operación real de un negocio (plan de mejoras C02 y C08): la primera
 * reserva o el primer pago de un cliente que cuenta (alumno o cliente facturable; quien
 * el negocio marcó como no facturable, p. ej. una persona de prueba, no cuenta). Corre
 * en la conexión del negocio.
 */
class HitosDeNegocio
{
    /**
     * Solo lo que pasó desde que el negocio se registró en la plataforma (`$desde`): lo
     * importado de su sistema anterior no es su primera operación aquí.
     */
    public function primeraOperacion(CarbonImmutable $desde): ?CarbonImmutable
    {
        $deCliente = static fn (Builder $q) => $q->where('tipo', TipoPersonaTenant::Miembro->value)->where('es_facturable', true);

        $reserva = ReservaTenant::query()
            ->whereIn('estado', [EstadoReserva::Confirmada->value, EstadoReserva::PendientePago->value])
            ->whereHas('persona', $deCliente)
            ->where('created_at', '>=', $desde->utc())
            ->min('created_at');
        $pago = OrdenTenant::query()
            ->where('estado', EstadoOrden::Pagada->value)
            ->whereHas('persona', $deCliente)
            ->where('pagada_en', '>=', $desde->utc())
            ->min('pagada_en');

        $fechas = array_filter([$reserva, $pago], static fn (mixed $f): bool => $f !== null);
        if ($fechas === []) {
            return null;
        }

        return CarbonImmutable::parse((string) min(array_map('strval', $fechas)), 'UTC');
    }
}
