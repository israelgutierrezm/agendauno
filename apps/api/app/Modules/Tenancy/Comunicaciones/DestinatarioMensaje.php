<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones;

/**
 * A quién va un mensaje automático: la persona del evento (el alumno o cliente), el
 * profesional de la cita (su usuario del equipo; en clases grupales no se avisa al
 * instructor por cada reserva) o el equipo del negocio que puede atenderlo
 * ({@see AvisosAlEquipo}).
 */
enum DestinatarioMensaje: string
{
    case Persona = 'persona';
    case Profesional = 'profesional';
    case Equipo = 'equipo';

    /**
     * Los eventos que admiten este destinatario (null = todos).
     *
     * @return list<string>|null
     */
    public function eventos(): ?array
    {
        return match ($this) {
            self::Persona => null,
            // Los que traen una cita (su sesión) de la que ubicar al profesional.
            self::Profesional => [
                'reserva.creada', 'reserva.confirmada', 'reserva.cancelada',
                'reserva.recordatorio_24h', 'reserva.recordatorio_2h',
            ],
            self::Equipo => AvisosAlEquipo::eventos(),
        };
    }
}
