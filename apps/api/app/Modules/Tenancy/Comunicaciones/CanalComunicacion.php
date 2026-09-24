<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones;

/**
 * Canal por el que se entrega un mensaje al destinatario. `Interno` = bandeja
 * in-app del miembro (sin dependencia externa); `Email` = correo.
 */
enum CanalComunicacion: string
{
    case Interno = 'interno';
    case Email = 'email';
}
