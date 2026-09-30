<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Comunicaciones\WhatsApp\ClienteWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\PlantillasWhatsApp;
use App\Modules\Tenancy\Comunicaciones\WhatsApp\TelefonoWhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * WhatsApp de la plataforma, para el superadministrador: la conexión (número o Phone
 * number ID y token de la cuenta de WhatsApp Business; el token nunca se devuelve) y
 * dos interruptores: con los dueños (verificar su número al registrarse, ADR 0070) y
 * de los negocios a sus clientes (ADR 0069). También las plantillas a registrar en
 * Meta y una prueba.
 */
class PlataformaWhatsAppController
{
    public function __construct(private readonly ClienteWhatsApp $whatsapp) {}

    public function mostrar(): JsonResponse
    {
        return response()->json(['data' => $this->presentar()]);
    }

    public function guardar(Request $request): JsonResponse
    {
        $validado = $request->validate([
            // Los dos usos se encienden por separado (cada mensaje cuesta).
            'negocios' => ['required', 'boolean'],
            'duenos' => ['required', 'boolean'],
            'phone_number_id' => ['nullable', 'string', 'max:40', 'regex:/^[0-9]*$/'],
            // Vacío conserva el que ya estaba.
            'token' => ['nullable', 'string', 'max:1000'],
            // App Secret de la app de Meta: firma los avisos de estado (ADR 0074).
            // Vacío conserva el que ya estaba.
            'app_secret' => ['nullable', 'string', 'max:255'],
        ]);
        $numero = (string) ($validado['phone_number_id'] ?? '');
        $token = $validado['token'] ?? null;
        $conToken = (is_string($token) && trim($token) !== '') || $this->whatsapp->paraEditar()['token_configurado'];

        if (($validado['negocios'] || $validado['duenos']) && ($numero === '' || ! $conToken)) {
            throw ValidationException::withMessages(['phone_number_id' => ['Para encenderlo carga el identificador del número y el token.']]);
        }

        $this->whatsapp->guardar((bool) $validado['negocios'], (bool) $validado['duenos'], $numero, $token, $validado['app_secret'] ?? null);

        return response()->json(['data' => $this->presentar()]);
    }

    /**
     * Manda la plantilla de muestra `hello_world` (la trae toda cuenta nueva de
     * WhatsApp Business, en inglés) para comprobar el número y el token.
     */
    public function probar(Request $request): JsonResponse
    {
        $validado = $request->validate(['telefono' => ['required', 'string', 'max:30']]);
        $telefono = TelefonoWhatsApp::normalizar($validado['telefono'])
            ?? throw ValidationException::withMessages(['telefono' => ['Ese número no es válido.']]);

        try {
            $this->whatsapp->enviarPlantilla($telefono, 'hello_world', [], 'en_US');
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['telefono' => [$e->getMessage()]]);
        }

        return response()->json(['data' => ['enviado' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(): array
    {
        return [
            ...$this->whatsapp->paraEditar(),
            'plantillas' => [
                'duenos' => PlantillasWhatsApp::paraDuenos(),
                'negocios' => PlantillasWhatsApp::paraNegocios(),
            ],
        ];
    }
}
