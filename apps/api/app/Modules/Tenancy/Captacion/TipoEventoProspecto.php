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
}
