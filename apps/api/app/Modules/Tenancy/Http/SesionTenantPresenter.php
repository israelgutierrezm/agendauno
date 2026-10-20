<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http;

use App\Modules\Tenancy\Application\CupoDeSesionTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Models\LugarPlataformaTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use App\Modules\Tenancy\PoliticaReservaTenant;
use App\Modules\Tenancy\Reservas\EstadoAtencionCita;
use App\Modules\Tenancy\Reservas\EstadoPagoCita;
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
     * Estados de reserva que ocupan un lugar en la sesión: la regla única de
     * {@see CupoDeSesionTenant} (las pendientes de pago retienen el cupo mientras se pagan).
     */
    public const OCUPAN_LUGAR = CupoDeSesionTenant::OCUPAN_LUGAR;

    /**
     * Los conteos que usan `clase` y `ocupacion` (para `withCount` o `loadCount`): los de
     * la regla única de cupo.
     *
     * @return array<string, Closure(Builder<ReservaTenant>|Builder<LugarPlataformaTenant>): void>
     */
    public static function conteos(): array
    {
        return CupoDeSesionTenant::conteos();
    }

    /**
     * La hora de la sesión en la zona de su sede: {@see FechasNegocioTenant::horaDeSesion()}.
     *
     * @return array{inicia_local: string|null, termina_local: string|null, utc_offset_min: int|null}
     */
    public static function horaLocal(?SesionTenant $sesion): array
    {
        return app(FechasNegocioTenant::class)->horaDeSesion($sesion);
    }

    /**
     * Carga los conteos si la consulta no los trajo (una sesión suelta).
     */
    public static function contar(SesionTenant $sesion): SesionTenant
    {
        $atributos = $sesion->getAttributes();
        if (! array_key_exists('ocupados', $atributos) || ! array_key_exists('en_espera', $atributos)
            || ! array_key_exists(CupoDeSesionTenant::ATRIBUTO_PLATAFORMA, $atributos)) {
            $sesion->loadCount(self::conteos());
        }

        return $sesion;
    }

    /**
     * Lo propio de una clase; null si la sesión es una cita. `libres` es lo que el canal
     * directo aún puede reservar: el cupo menos lo ocupado y lo que otros canales tienen
     * apartados (la misma cuenta del motor). En una lista, {@see CupoDeSesionTenant::cargar()}
     * trae lo apartado de todo el lote; si no, se consulta para esta sesión.
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
        // Reservas y quienes vienen por Wellhub o TotalPass (ADR 0148).
        $ocupados = CupoDeSesionTenant::ocupadosCargados($sesion);
        // Cómo se habilita la reserva: con plan o pagando la clase suelta.
        $dePago = $sesion->oferta?->politica_reserva === PoliticaReservaTenant::Pago;

        return [
            'capacidad' => $capacidad,
            'ocupados' => $ocupados,
            'libres' => app(CupoDeSesionTenant::class)->libres($sesion),
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
        $ocupados = CupoDeSesionTenant::ocupadosCargados($sesion);

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
