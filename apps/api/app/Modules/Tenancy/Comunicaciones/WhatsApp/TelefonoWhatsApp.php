<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Comunicaciones\WhatsApp;

/**
 * El celular de una persona en el formato que pide WhatsApp: solo dígitos, con la
 * clave del país y sin "+" (525512345678). Sin lada se asume la del país de la
 * plataforma (`agendauno.whatsapp.lada`, México). El prefijo "1" que México usaba
 * para celulares (521…) ya no va.
 */
final class TelefonoWhatsApp
{
    public static function normalizar(?string $celular): ?string
    {
        $celular = trim((string) $celular);
        if ($celular === '') {
            return null;
        }

        $digitos = (string) preg_replace('/\D/', '', $celular);
        if (! str_starts_with($celular, '+')) {
            $lada = (string) config('agendauno.whatsapp.lada', '52');
            if (strlen($digitos) === 10) {
                $digitos = $lada.$digitos;
            } elseif (! str_starts_with($digitos, $lada)) {
                return null;
            }
        }
        if (strlen($digitos) === 13 && str_starts_with($digitos, '521')) {
            $digitos = '52'.substr($digitos, 3);
        }

        return strlen($digitos) >= 8 && strlen($digitos) <= 15 ? $digitos : null;
    }
}
