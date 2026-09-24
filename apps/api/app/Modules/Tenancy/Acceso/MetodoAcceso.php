<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Acceso;

/**
 * Metodo con el que el miembro presenta su credencial en la puerta (R12).
 */
enum MetodoAcceso: string
{
    case Qr = 'qr';
    case Pin = 'pin';
    case Nfc = 'nfc';
    case Manual = 'manual';
}
