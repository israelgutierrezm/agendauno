<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Models\DerechoTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonImmutable;

/**
 * El tope de reservas de un plan (ADR 0182): cuántas clases puede tener apartadas por
 * día y por semana (lunes a domingo) quien lo tiene, para que nadie acapare lugares. Se
 * cuentan las reservas de ese plan que ocupan lugar o esperan en la lista, por el día de
 * la clase en la zona de su sede, en OTRAS clases: la propia espera en la clase que se
 * evalúa no cuenta (si no, nadie con tope recibiría el lugar de su lista de espera), ni
 * la reserva que se está moviendo al reprogramar. El tope se congela en el derecho al
 * venderlo.
 *
 * El motor lo revisa al elegir el plan y otra vez con el derecho bloqueado (dos reservas
 * al mismo tiempo no pasan las dos).
 */
class TopesDeReservaTenant
{
    /**
     * Estados que cuentan para el tope.
     *
     * @var list<string>
     */
    private const CUENTAN = [
        EstadoReserva::Confirmada->value, EstadoReserva::Ofrecida->value,
        EstadoReserva::EnEspera->value, EstadoReserva::PendientePago->value,
    ];

    /** ¿El derecho tiene algún tope? */
    public static function tieneTope(DerechoTenant $derecho): bool
    {
        return (int) ($derecho->max_reservas_dia ?? 0) > 0 || (int) ($derecho->max_reservas_semana ?? 0) > 0;
    }

    /**
     * Por qué ese plan ya no alcanza para la clase (llegó a su tope del día o de la
     * semana), o null si todavía puede.
     */
    public function alcanzado(DerechoTenant $derecho, SesionTenant $sesion, ?int $exceptoReserva = null): ?string
    {
        if (! self::tieneTope($derecho)) {
            return null;
        }
        $zona = (string) ($sesion->zona_horaria ?: 'UTC');
        $dia = CarbonImmutable::instance($sesion->inicia_en)->setTimezone($zona)->startOfDay();

        $porDia = (int) ($derecho->max_reservas_dia ?? 0);
        if ($porDia > 0 && $this->contar($derecho, $sesion, $dia, $dia->addDay(), $exceptoReserva) >= $porDia) {
            return "Tu plan permite {$porDia} ".($porDia === 1 ? 'reserva' : 'reservas').' por día y ya las tienes para ese día.';
        }
        $porSemana = (int) ($derecho->max_reservas_semana ?? 0);
        $lunes = $dia->startOfWeek(CarbonImmutable::MONDAY);
        if ($porSemana > 0 && $this->contar($derecho, $sesion, $lunes, $lunes->addWeek(), $exceptoReserva) >= $porSemana) {
            return "Tu plan permite {$porSemana} ".($porSemana === 1 ? 'reserva' : 'reservas').' por semana y ya las tienes para esa semana.';
        }

        return null;
    }

    private function contar(DerechoTenant $derecho, SesionTenant $sesion, CarbonImmutable $desde, CarbonImmutable $hasta, ?int $exceptoReserva): int
    {
        return ReservaTenant::query()
            ->where('derecho_id', $derecho->getKey())
            ->whereIn('estado', self::CUENTAN)
            ->where('sesion_id', '!=', $sesion->getKey())
            ->when($exceptoReserva !== null, fn ($q) => $q->whereKeyNot($exceptoReserva))
            ->whereHas('sesion', fn ($q) => $q->where('inicia_en', '>=', $desde->utc())->where('inicia_en', '<', $hasta->utc()))
            ->count();
    }
}
