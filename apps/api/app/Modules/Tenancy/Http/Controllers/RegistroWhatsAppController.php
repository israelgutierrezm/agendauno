<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\VerificacionWhatsAppDueno;
use App\Modules\Tenancy\Application\VerificarRecaptcha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Verificación del WhatsApp del dueño al registrarse (ADR 0070), pública: si está
 * disponible, mandar el código y confirmarlo. El comprobante que devuelve se manda
 * al crear el negocio (`whatsapp_verificacion` en POST /registro).
 */
class RegistroWhatsAppController
{
    public function __construct(
        private readonly VerificacionWhatsAppDueno $verificacion,
        private readonly VerificarRecaptcha $recaptcha,
    ) {}

    public function disponible(): JsonResponse
    {
        return response()->json(['data' => ['disponible' => $this->verificacion->disponible()]]);
    }

    public function codigo(Request $request): JsonResponse
    {
        $telefono = $this->telefono($request, ['recaptcha_token' => ['nullable', 'string']]);
        if (! $this->recaptcha->aprobado($request->string('recaptcha_token')->value(), $request->ip())) {
            throw ValidationException::withMessages([
                'recaptcha' => ['No pudimos verificar que no eres un robot. Recarga e inténtalo de nuevo.'],
            ]);
        }

        $this->verificacion->enviarCodigo($telefono, $request->ip());

        return response()->json(['data' => ['enviado' => true]], 201);
    }

    public function verificar(Request $request): JsonResponse
    {
        $telefono = $this->telefono($request, ['codigo' => ['required', 'string', 'regex:/^\d{6}$/']]);

        return response()->json(['data' => [
            'verificacion' => $this->verificacion->verificar($telefono, (string) $request->input('codigo')),
        ]]);
    }

    /**
     * El número como lo pide WhatsApp, de la lada y el número del registro.
     *
     * @param  array<string, list<string>>  $extra
     */
    private function telefono(Request $request, array $extra): string
    {
        $request->merge(['contacto_whatsapp_pais' => preg_replace('/\D+/', '', (string) $request->input('contacto_whatsapp_pais', '52')) ?: '52']);
        $validado = $request->validate([
            'contacto_whatsapp_pais' => ['required', 'string', 'regex:/^\d{1,4}$/'],
            'contacto_telefono' => ['required', 'string', 'regex:/^[0-9 \-]{7,15}$/'],
            ...$extra,
        ]);

        return VerificacionWhatsAppDueno::telefono((string) $validado['contacto_whatsapp_pais'], (string) $validado['contacto_telefono'])
            ?? throw ValidationException::withMessages(['contacto_telefono' => ['Ese número no es válido para WhatsApp.']]);
    }
}
