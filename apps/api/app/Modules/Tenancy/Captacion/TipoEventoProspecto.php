<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Captacion;

/**
 * Lo que queda en el historial de un prospecto.
 */
enum TipoEventoProspecto: string
{
    case Creado = 'creado';
    case Reenvio = 'reenvio';
    case Estado = 'estado';
    case Asignado = 'asignado';
    case Nota = 'nota';
    case Seguimiento = 'seguimiento';
    case Invitado = 'invitado';
    case Convertido = 'convertido';
    // El equipo le escribió o le llamó (WhatsApp, llamada o correo; ADR 0138).
    case Contacto = 'contacto';
    // Su responsable no lo atendió a tiempo (ADR 0138).
    case SinAtender = 'sin_atender';

    /**
     * Lo que cuenta como haberlo atendido: después de esto ya no está «sin atender».
     *
     * @return list<self>
     */
    public static function atencion(): array
    {
        return [self::Estado, self::Nota, self::Contacto, self::Seguimiento, self::Invitado, self::Convertido];
    }
}
