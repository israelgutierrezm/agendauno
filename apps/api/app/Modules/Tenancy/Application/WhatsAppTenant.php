<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application;

use App\Modules\Tenancy\Comunicaciones\CanalComunicacion;
use App\Modules\Tenancy\Comunicaciones\DestinatarioMensaje;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\PlantillaMensajeTenant;
use App\Modules\Tenancy\Models\Usuario;

/**
 * WhatsApp en el negocio (ADR 0069): se le ofrece a sus clientes (aceptar avisos al
 * agendar, en su cuenta o diciéndoselo a recepción) solo si la plataforma lo tiene
 * encendido, el superadministrador lo activó en el negocio (ADR 0083) y el negocio
 * encendió algún aviso por WhatsApp. Aceptar guarda cuándo (Meta pide el
 * consentimiento); sin él no se le manda nada.
 */
class WhatsAppTenant
{
    public function __construct(
        private readonly ClienteWhatsApp $cliente,
        private readonly RegistrarAuditoria $auditoria,
        private readonly GestorDeConexionTenant $gestor,
    ) {}

    public function enUso(): bool
    {
        return $this->cliente->activoPara($this->gestor->actual())
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

    /**
     * Lo marca el equipo porque el cliente se lo pidió (p. ej. al agendar por
     * teléfono). Queda en la bitácora quién y cuándo. Aceptar solo si el negocio los
     * usa; retirarlo, siempre.
     */
    public function registrarPorEquipo(PersonaTenant $persona, bool $acepta, ?Usuario $actor): void
    {
        $antes = $persona->whatsapp_aceptado_en !== null;
        if ($acepta === $antes || ($acepta && ! $this->enUso())) {
            return;
        }

        $this->aceptar($persona, $acepta);
        $this->auditoria->registrar(
            $actor,
            $acepta ? 'miembro.whatsapp_aceptado' : 'miembro.whatsapp_retirado',
            'persona',
            (string) $persona->ulid,
            ['acepta_whatsapp' => $antes],
            ['acepta_whatsapp' => $acepta],
            $acepta ? 'El cliente pidió los avisos por WhatsApp.' : null,
        );
    }

    /**
     * La persona contestó BAJA al número de AgendaUno (ADR 0083): se retira su
     * consentimiento y queda en la bitácora, sin actor.
     */
    public function retirarPorRespuesta(PersonaTenant $persona): bool
    {
        if ($persona->whatsapp_aceptado_en === null) {
            return false;
        }

        $this->aceptar($persona, false);
        $this->auditoria->registrar(
            null,
            'miembro.whatsapp_retirado',
            'persona',
            (string) $persona->ulid,
            ['acepta_whatsapp' => true],
            ['acepta_whatsapp' => false],
            'Lo pidió contestando BAJA por WhatsApp.',
        );

        return true;
    }
}
