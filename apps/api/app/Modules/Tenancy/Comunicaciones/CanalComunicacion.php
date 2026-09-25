<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones;

use App\Modules\Tenancy\Comunicaciones\Push\ClienteFcm;

/**
 * Canal por el que se entrega un mensaje al destinatario. `Interno` = bandeja
 * in-app del miembro (sin dependencia externa); `Email` = correo; `Push` =
 * notificación en los teléfonos donde tiene la app (FCM).
 */
enum CanalComunicacion: string
{
    case Interno = 'interno';
    case Email = 'email';
    case Push = 'push';

    /**
     * Los canales que se pueden usar ahora: push solo si la plataforma tiene FCM.
     *
     * @return list<self>
     */
    public static function disponibles(): array
    {
        $canales = [self::Interno, self::Email];
        if (app(ClienteFcm::class)->configurado()) {
            $canales[] = self::Push;
        }

        return $canales;
    }
}
