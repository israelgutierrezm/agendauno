<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\VerificacionWhatsAppDueno;
use App\Modules\Tenancy\CatalogoPaises;
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
 * registrarse, verificarlo aquí con un código (ADR 0070). También puede cambiar el
 * número (ADR 0075). Queda en la bitácora.
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
     * Cambiar el número: manda el código al número nuevo (ADR 0075).
     */
    public function codigoCambio(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        ['telefono' => $telefono] = $this->numeroNuevo($request, $estudio, []);
        $this->verificacion->enviarCodigo($telefono, $request->ip());

        return response()->json(['data' => ['enviado' => true]], 201);
    }

    /**
     * Cambia el WhatsApp del negocio (ADR 0075). Con WhatsApp con los dueños pide el
     * código que llegó al número nuevo, y el número cambia solo al confirmarlo: queda
     * verificado y acepta los avisos. Sin él, se guarda sin verificar.
     */
    public function cambiar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $conCodigo = $this->verificacion->disponible();
        $nuevo = $this->numeroNuevo($request, $estudio, $conCodigo ? ['codigo' => ['required', 'string', 'regex:/^\d{6}$/']] : []);

        if ($conCodigo) {
            $comprobante = $this->verificacion->verificar($nuevo['telefono'], (string) $request->input('codigo'));
            $verificacion = $this->verificacion->comprobanteValido($nuevo['telefono'], $comprobante);
            if ($verificacion !== null) {
                $this->verificacion->usar($verificacion);
            }
        }

        $antes = $estudio->whatsappCompleto();
        $estudio->forceFill([
            'contacto_whatsapp_pais' => $nuevo['pais'],
            'contacto_telefono' => $nuevo['numero'],
            'contacto_whatsapp_verificado_en' => $conCodigo ? now() : null,
            'contacto_whatsapp_aceptado_en' => $conCodigo ? now() : null,
        ])->save();
        $this->auditoria->registrar(
            $this->actor($request),
            'negocio.whatsapp_cambiado',
            'estudio',
            (string) $estudio->ulid,
            ['whatsapp' => $antes],
            ['whatsapp' => $estudio->whatsappCompleto(), 'verificado' => $conCodigo],
        );

        return response()->json(['data' => $this->presentar($estudio)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Estudio $estudio): array
    {
        return [
            'correo' => $estudio->contacto_email,
            // El WhatsApp del negocio, el mismo de su página; se cambia aquí (ADR 0075).
            'numero' => $estudio->whatsappCompleto(),
            'pais' => (string) ($estudio->contacto_whatsapp_pais ?? '52'),
            // Solo si la plataforma tiene WhatsApp con los dueños.
            'whatsapp' => $this->verificacion->disponible() ? [
                'numero' => $estudio->whatsappCompleto(),
                'verificado' => $estudio->contacto_whatsapp_verificado_en !== null,
                'acepta' => $estudio->contacto_whatsapp_aceptado_en !== null,
            ] : null,
        ];
    }

    /**
     * El número nuevo (lada y número, como en el registro) y cómo lo pide WhatsApp.
     * Si ya es el del negocio y no hay nada que verificar, se rechaza.
     *
     * @param  array<string, list<string>>  $extra
     * @return array{telefono: string, pais: string, numero: string}
     */
    private function numeroNuevo(Request $request, Estudio $estudio, array $extra): array
    {
        // Sin lada, la del país del negocio (ADR 0103).
        $ladaPais = CatalogoPaises::lada($estudio->pais) ?? '52';
        $request->merge(['contacto_whatsapp_pais' => preg_replace('/\D+/', '', (string) $request->input('contacto_whatsapp_pais', $ladaPais)) ?: $ladaPais]);
        $validado = $request->validate([
            'contacto_whatsapp_pais' => ['required', 'string', 'regex:/^\d{1,4}$/'],
            'contacto_telefono' => ['required', 'string', 'regex:/^[0-9 \-]{7,15}$/'],
            ...$extra,
        ]);
        $pais = (string) $validado['contacto_whatsapp_pais'];
        $numero = trim((string) $validado['contacto_telefono']);
        $telefono = VerificacionWhatsAppDueno::telefono($pais, $numero)
            ?? throw ValidationException::withMessages(['contacto_telefono' => ['Ese número no es válido para WhatsApp.']]);

        $esElMismo = $telefono === TelefonoWhatsApp::normalizar($estudio->whatsappCompleto(), $estudio->contacto_whatsapp_pais);
        if ($esElMismo && ($estudio->contacto_whatsapp_verificado_en !== null || ! $this->verificacion->disponible())) {
            throw ValidationException::withMessages(['contacto_telefono' => ['Ese ya es el WhatsApp de tu negocio.']]);
        }

        return ['telefono' => $telefono, 'pais' => $pais, 'numero' => $numero];
    }

    private function telefono(Estudio $estudio): string
    {
        return TelefonoWhatsApp::normalizar($estudio->whatsappCompleto(), $estudio->contacto_whatsapp_pais)
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
