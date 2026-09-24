<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

/**
 * La URL de un webhook saliente apunta a un destino no permitido (red interna,
 * dirección reservada, sin https, con credenciales o que no resuelve).
 */
class DestinoWebhookNoPermitido extends TenancyException
{
    public function codigo(): string
    {
        return 'WEBHOOK_DESTINATION_NOT_ALLOWED';
    }
}
