<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\VerificacionWhatsAppDueno;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Los avisos de AgendaUno al dueño, desde su panel (ADR 0072): a qué correo le
 * llegan y, si la plataforma tiene WhatsApp con los dueños, si también le llegan
 * por WhatsApp. Puede dejar de recibirlos y, si no verificó su número al
 * registrarse, verificarlo aquí con un código (ADR 0070). Queda en la bitácora.
 */
class AvisosPlataformaTenantController
{
    public function __construct(
        private readonly VerificacionWhatsAppDueno $verificacion,
        private readonly RegistrarAuditoria $auditoria,
    ) {}

    public function mostrar(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentar($this->estudio($request))]);
    }

    /**
     * `acepta_whatsapp`: recibir (o dejar de recibir) los avisos por WhatsApp.
     * Aceptarlos pide el número verificado.
     */
    public function guardar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $acepta = (bool) $request->validate(['acepta_whatsapp' => ['required', 'boolean']])['acepta_whatsapp'];

        if ($acepta && (! $this->verificacion->disponible() || $estudio->contacto_whatsapp_verificado_en === null)) {
            throw ValidationException::withMessages(['acepta_whatsapp' => ['Primero verifica tu número de WhatsApp.']]);
        }
        $antes = $estudio->contacto_whatsapp_aceptado_en !== null;
        if ($acepta !== $antes) {
            $estudio->forceFill(['contacto_whatsapp_aceptado_en' => $acepta ? now() : null])->save();
            $this->auditoria->registrar(
                $this->actor($request),
                $acepta ? 'negocio.whatsapp_aceptado' : 'negocio.whatsapp_retirado',
                'estudio',
                (string) $estudio->ulid,
                ['acepta_whatsapp' => $antes],
                ['acepta_whatsapp' => $acepta],
            );
        }

        return response()->json(['data' => $this->presentar($estudio)]);
    }

    /**
     * Manda el código al WhatsApp del negocio.
     */
    public function codigo(Request $request): JsonResponse
    {
        $this->verificacion->enviarCodigo($this->telefono($this->estudio($request)), $request->ip());

        return response()->json(['data' => ['enviado' => true]], 201);
    }

    /**
     * Confirma el código: el número queda verificado y acepta los avisos.
     */
    public function verificar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $codigo = (string) $request->validate(['codigo' => ['required', 'string', 'regex:/^\d{6}$/']])['codigo'];
        $telefono = $this->telefono($estudio);

        $comprobante = $this->verificacion->verificar($telefono, $codigo);
        $verificacion = $this->verificacion->comprobanteValido($telefono, $comprobante);
        if ($verificacion !== null) {
            $this->verificacion->usar($verificacion);
        }
        $estudio->forceFill([
            'contacto_whatsapp_verificado_en' => now(),
            'contacto_whatsapp_aceptado_en' => now(),
        ])->save();
        $this->auditoria->registrar($this->actor($request), 'negocio.whatsapp_verificado', 'estudio', (string) $estudio->ulid, null, ['whatsapp' => $estudio->whatsappCompleto()]);

        return response()->json(['data' => $this->presentar($estudio)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Estudio $estudio): array
    {
        return [
            'correo' => $estudio->contacto_email,
            // Solo si la plataforma tiene WhatsApp con los dueños.
            'whatsapp' => $this->verificacion->disponible() ? [
                'numero' => $estudio->whatsappCompleto(),
                'verificado' => $estudio->contacto_whatsapp_verificado_en !== null,
                'acepta' => $estudio->contacto_whatsapp_aceptado_en !== null,
            ] : null,
        ];
    }

    private function telefono(Estudio $estudio): string
    {
        return TelefonoWhatsApp::normalizar($estudio->whatsappCompleto())
            ?? throw ValidationException::withMessages(['contacto_telefono' => ['Tu negocio no tiene un número de WhatsApp válido. Escríbenos para corregirlo.']]);
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }

    private function actor(Request $request): ?Usuario
    {
        $actor = $request->attributes->get('usuario_tenant');

        return $actor instanceof Usuario ? $actor : null;
    }
}
