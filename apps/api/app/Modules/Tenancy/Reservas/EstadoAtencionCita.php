<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Reservas;

use App\Modules\Tenancy\Asistencia\EstadoAsistencia;
use App\Modules\Tenancy\EstadoSesionTenant;
use App\Modules\Tenancy\Models\ReservaTenant;
use App\Modules\Tenancy\Models\SesionTenant;
use Carbon\CarbonInterface;

/**
 * En qué va la atención de una cita a una hora dada (qué pasa con el cliente). Lo
 * calcula el servidor para que la web y la app no lo deduzcan cada una a su modo
 * (ADR 0104); el pago va aparte en {@see EstadoPagoCita}.
 *
 * Sin registro de asistencia está `confirmada` (por atender) mientras no termina y
 * `sin_registrar` cuando ya terminó; con «llegó», según la hora: `llego` (antes de
 * empezar), `en_servicio` (durante) o `completada` (después).
 */
enum EstadoAtencionCita: string
{
    case Confirmada = 'confirmada';
    case SinRegistrar = 'sin_registrar';
    case Llego = 'llego';
    case EnServicio = 'en_servicio';
    case Completada = 'completada';
    case NoAsistio = 'no_asistio';
    case Cancelada = 'cancelada';

    /**
     * @param  ReservaTenant  $reserva  la del titular de la cita (con su asistencia)
     */
    public static function de(SesionTenant $sesion, ReservaTenant $reserva, CarbonInterface $ahora): self
    {
        if ($sesion->estado !== EstadoSesionTenant::Programada
            || in_array($reserva->estado, [EstadoReserva::Cancelada, EstadoReserva::Expirada], true)) {
            return self::Cancelada;
        }

        $asistencia = $reserva->asistencia?->estado;
        if ($asistencia === EstadoAsistencia::Ausente) {
            return self::NoAsistio;
        }
        if ($asistencia === EstadoAsistencia::Presente) {
            if ($ahora->lessThan($sesion->inicia_en)) {
                return self::Llego;
            }

            return $ahora->lessThan($sesion->termina_en) ? self::EnServicio : self::Completada;
        }

        return $ahora->lessThan($sesion->termina_en) ? self::Confirmada : self::SinRegistrar;
    }
}
