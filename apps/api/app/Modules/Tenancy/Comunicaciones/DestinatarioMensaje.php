<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones;

/**
 * A quién va un mensaje automático: la persona del evento (el alumno o cliente) o el
 * profesional de la cita (su usuario del equipo). En clases grupales no se avisa al
 * instructor por cada reserva: serían demasiadas notificaciones.
 */
enum DestinatarioMensaje: string
{
    case Persona = 'persona';
    case Profesional = 'profesional';
}
