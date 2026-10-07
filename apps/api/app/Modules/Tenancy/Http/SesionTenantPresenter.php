<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http;

use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Reservas\EstadoAtencionCita;
use App\Modules\Tenancy\Reservas\EstadoPagoCita;
use App\Modules\Tenancy\Reservas\EstadoReserva;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Forma común de una sesión para la web y la app (ADR 0104, contrato de la agenda):
 * `tipo` es el de la sesión (la modalidad del negocio) y lo propio de cada tipo va en
 * su bloque — `clase` (cupo, lista de espera, pago por clase) o `cita` (en qué va,
 * calculado aquí) —, con el otro en null. `ocupacion` es el porcentaje que se muestra,
 * decidido por el servidor: la web y la app no lo eligen ni lo calculan.
 */
class SesionTenantPresenter
{
    /**
     * Estados de reserva que ocupan un lugar en la sesión (las pendientes de pago
     * retienen el cupo mientras se pagan).
     */
    public const OCUPAN_LUGAR = [
        EstadoReserva::Confirmada->value,
        EstadoReserva::Ofrecida->value,
        EstadoReserva::PendientePago->value,
    ];

    /**
     * Los conteos que usan `clase` y `ocupacion` (para `withCount` o `loadCount`).
     *
     * @return array<string, Closure(Builder<ReservaTenant>): void>
     */
    public static function conteos(): array
    {
        return [
            'reservas as ocupados' => static function (Builder $q): void {
                $q->whereIn('estado', self::OCUPAN_LUGAR);
            },
            'reservas as en_espera' => static function (Builder $q): void {
                $q->where('estado', EstadoReserva::EnEspera->value);
            },
        ];
    }

    /**
     * Carga los conteos si la consulta no los trajo (una sesión suelta).
     */
    public static function contar(SesionTenant $sesion): SesionTenant
    {
        if (! array_key_exists('ocupados', $sesion->getAttributes()) || ! array_key_exists('en_espera', $sesion->getAttributes())) {
            $sesion->loadCount(self::conteos());
        }

        return $sesion;
    }

    /**
     * Lo propio de una clase; null si la sesión es una cita.
     *
     * @return array{capacidad: int|null, ocupados: int, libres: int|null, en_espera: int, lugares: int, de_pago: bool, precio_minor: int|null}|null
     */
    public static function clase(SesionTenant $sesion): ?array
    {
        if ($sesion->esCita()) {
            return null;
        }

        self::contar($sesion);
        $capacidad = $sesion->capacidad !== null ? (int) $sesion->capacidad : null;
        $ocupados = (int) $sesion->getAttribute('ocupados');
        // Cómo se habilita la reserva: con plan o pagando la clase suelta.
        $dePago = $sesion->oferta?->politica_reserva === PoliticaReservaTenant::Pago;

        return [
            'capacidad' => $capacidad,
            'ocupados' => $ocupados,
            'libres' => $capacidad !== null ? max(0, $capacidad - $ocupados) : null,
            'en_espera' => (int) $sesion->getAttribute('en_espera'),
            // Lugares numerados de la oferta (0 = sin mapa de lugares).
            'lugares' => (int) ($sesion->oferta->lugares ?? 0),
            'de_pago' => $dePago,
            'precio_minor' => $dePago ? (int) ($sesion->oferta->precio_clase_minor ?? 0) : null,
        ];
    }

    /**
     * La ocupación que se muestra: la del cupo de una clase, de 0 a 100 (con sobrecupo
     * se queda en 100). Null en una cita (es de una persona) o en una clase sin cupo.
     *
     * @return array{ocupados: int, capacidad: int, porcentaje: int}|null
     */
    public static function ocupacion(SesionTenant $sesion): ?array
    {
        if ($sesion->esCita() || $sesion->capacidad === null || (int) $sesion->capacidad <= 0) {
            return null;
        }

        self::contar($sesion);
        $capacidad = (int) $sesion->capacidad;
        $ocupados = (int) $sesion->getAttribute('ocupados');

        return [
            'ocupados' => $ocupados,
            'capacidad' => $capacidad,
            'porcentaje' => min(100, (int) round($ocupados * 100 / $capacidad)),
        ];
    }

    /**
     * En qué va la cita (atención y pago), a la hora `ahora`.
     *
     * @param  ReservaTenant  $titular  la reserva de quien se atiende
     * @return array{estado_atencion: string, estado_pago: string|null}
     */
    public static function estadoCita(SesionTenant $sesion, ReservaTenant $titular, CarbonInterface $ahora): array
    {
        return [
            'estado_atencion' => EstadoAtencionCita::de($sesion, $titular, $ahora)->value,
            'estado_pago' => EstadoPagoCita::de($titular)?->value,
        ];
    }
}
