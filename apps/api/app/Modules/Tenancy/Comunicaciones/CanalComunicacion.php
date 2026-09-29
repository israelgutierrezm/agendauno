<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones;

use App\Modules\Tenancy\Comunicaciones\Push\ClienteFcm;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;

/**
 * Canal por el que se entrega un mensaje al destinatario. `Interno` = bandeja
 * in-app del miembro (sin dependencia externa); `Email` = correo; `Push` =
 * notificación en los teléfonos donde tiene la app (FCM); `WhatsApp` = plantilla
 * aprobada por Meta al celular (ADR 0069), solo si la plataforma lo tiene encendido.
 */
enum CanalComunicacion: string
{
    case Interno = 'interno';
    case Email = 'email';
    case Push = 'push';
    case WhatsApp = 'whatsapp';

    /**
     * Los canales que se pueden usar ahora: push solo si la plataforma tiene FCM;
     * WhatsApp solo si el superadministrador lo encendió (si no, el negocio ni lo ve).
     *
     * @return list<self>
     */
    public static function disponibles(): array
    {
        $canales = [self::Interno, self::Email];
        if (app(ClienteFcm::class)->configurado()) {
            $canales[] = self::Push;
        }
        if (app(ClienteWhatsApp::class)->activoParaNegocios()) {
            $canales[] = self::WhatsApp;
        }

        return $canales;
    }

    /**
     * Canales de un envío masivo: WhatsApp no (sus avisos son plantillas fijas de
     * utilidad; una promoción necesitaría otra plantilla y cuesta más).
     *
     * @return list<self>
     */
    public static function paraDifusiones(): array
    {
        return array_values(array_filter(self::disponibles(), static fn (self $canal): bool => $canal !== self::WhatsApp));
    }
}
