<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\DestinatarioMensaje;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaMensajeTenant;

/**
 * WhatsApp en el negocio (ADR 0069): se le ofrece a sus clientes (aceptar avisos al
 * agendar o en su cuenta) solo si la plataforma lo tiene encendido y el negocio
 * encendió algún aviso por WhatsApp. Aceptar guarda cuándo (Meta pide el
 * consentimiento); sin él no se le manda nada.
 */
class WhatsAppTenant
{
    public function __construct(private readonly ClienteWhatsApp $cliente) {}

    public function enUso(): bool
    {
        return $this->cliente->activo()
            && PlantillaMensajeTenant::query()
                ->where('canal', CanalComunicacion::WhatsApp->value)
                ->where('destinatario', DestinatarioMensaje::Persona->value)
                ->where('activo', true)
                ->exists();
    }

    /**
     * Acepta o retira los avisos por WhatsApp. Aceptar de nuevo conserva la fecha
     * original.
     */
    public function aceptar(PersonaTenant $persona, bool $acepta): void
    {
        if ($acepta && $persona->whatsapp_aceptado_en === null) {
            $persona->forceFill(['whatsapp_aceptado_en' => now()])->save();
        } elseif (! $acepta && $persona->whatsapp_aceptado_en !== null) {
            $persona->forceFill(['whatsapp_aceptado_en' => null])->save();
        }
    }
}
