<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\BajaDePersonaTenant;
use App\Modules\Tenancy\Application\ExportarDatosPersonaTenant;
use App\Modules\Tenancy\Application\PersonaDeUsuarioTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\RegistrarAuditoria;
use App\Modules\Tenancy\Application\WhatsAppTenant;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\SolicitudPrivacidadTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Privacidad del alumno (derechos ARCO frente al negocio): descargar sus datos
 * (acceso/portabilidad), oponerse a promociones, aceptar o retirar los avisos por
 * WhatsApp (si el negocio los usa, ADR 0069) y pedir la baja de sus datos
 * (cancelación). La rectificación está en "Mi perfil".
 *
 * Descargar los datos y pedir la baja se confirman con la contraseña: así se sabe
 * que quien tiene la sesión abierta es de verdad la persona.
 */
class MiPrivacidadTenantController
{
    public function __construct(
        private readonly PersonaDeUsuarioTenant $personas,
        private readonly ExportarDatosPersonaTenant $exportar,
        private readonly BajaDePersonaTenant $baja,
        private readonly WhatsAppTenant $whatsapp,
        private readonly RegistrarAuditoria $auditoria,
        private readonly RegionNegocioTenant $region,
    ) {}

    public function mostrar(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presentar($this->persona($request))]);
    }

    public function actualizar(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        $validado = $request->validate([
            'recibe_promociones' => ['sometimes', 'boolean'],
            'acepta_whatsapp' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('recibe_promociones', $validado)) {
            $persona->update(['recibe_promociones' => (bool) $validado['recibe_promociones']]);
        }
        // Retirarlo se puede siempre; aceptarlo, solo si el negocio los usa.
        if (array_key_exists('acepta_whatsapp', $validado) && (! $validado['acepta_whatsapp'] || $this->whatsapp->enUso())) {
            $this->whatsapp->aceptar($persona, (bool) $validado['acepta_whatsapp']);
        }

        return response()->json(['data' => $this->presentar($persona)]);
    }

    public function datos(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        $usuario = $this->confirmarContrasena($request);
        $this->auditoria->registrar($usuario, 'privacidad.datos_descargados', 'persona', (string) $persona->ulid);
        $nombre = 'mis-datos-'.Str::slug((string) $request->route('estudio')).'.json';

        return response()->json(['data' => $this->exportar->para($persona)], 200, [
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function solicitarBaja(Request $request): JsonResponse
    {
        $persona = $this->persona($request);
        $this->confirmarContrasena($request);
        $validado = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);
        $motivo = $validado['motivo'] ?? null;
        $this->baja->solicitar($persona, is_string($motivo) && trim($motivo) !== '' ? trim($motivo) : null);

        return response()->json(['data' => $this->presentar($persona)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(PersonaTenant $persona): array
    {
        $solicitud = SolicitudPrivacidadTenant::query()->where('persona_id', $persona->getKey())->latest('id')->first();

        return [
            'recibe_promociones' => (bool) ($persona->recibe_promociones ?? true),
            // Solo si el negocio manda avisos por WhatsApp (y la plataforma lo tiene).
            'whatsapp_disponible' => $this->whatsapp->enUso(),
            'acepta_whatsapp' => $persona->whatsapp_aceptado_en !== null,
            // Sin un celular válido no hay a dónde mandarlos.
            'whatsapp_con_celular' => TelefonoWhatsApp::normalizar($persona->celular, $this->region->lada()) !== null,
            'baja' => $solicitud instanceof SolicitudPrivacidadTenant ? [
                'estado' => $solicitud->estado,
                'solicitada_en' => $solicitud->created_at?->toIso8601String(),
                'respuesta' => $solicitud->respuesta,
            ] : null,
        ];
    }

    /**
     * Confirma con la contraseña que es la persona de la sesión. Sin contraseña (p. ej.
     * solo entra con Google) primero debe crear una en Mi perfil.
     */
    private function confirmarContrasena(Request $request): Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);
        $validado = $request->validate(['password' => ['required', 'string']]);
        if ($usuario->password === null || $usuario->password === '') {
            throw ValidationException::withMessages(['password' => ['Crea una contraseña en Mi perfil para confirmar que eres tú.']]);
        }
        if (! Hash::check((string) $validado['password'], (string) $usuario->password)) {
            throw ValidationException::withMessages(['password' => ['La contraseña no es correcta.']]);
        }

        return $usuario;
    }

    private function persona(Request $request): PersonaTenant
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);
        $persona = $this->personas->buscar($usuario);
        abort_unless($persona instanceof PersonaTenant, 403, 'No tienes un perfil de alumno en este negocio.');

        return $persona;
    }
}
