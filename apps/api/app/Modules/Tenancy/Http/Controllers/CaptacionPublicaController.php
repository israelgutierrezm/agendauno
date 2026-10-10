<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CaptacionTenant;
use App\Modules\Tenancy\Application\VerificarRecaptcha;
use App\Modules\Tenancy\Application\WaiversTenant;
use App\Modules\Tenancy\Models\CampoFormularioCaptacionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\FormularioCaptacionTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Los formularios de captación del negocio, sin sesión (ADR 0117): los usa su página
 * («Pedir acceso»), el enlace o QR que comparte y el formulario embebido en su propio
 * sitio. Quien lo llena llega a Prospectos; no crea cuenta ni reserva.
 */
class CaptacionPublicaController
{
    public function __construct(private readonly CaptacionTenant $captacion) {}

    /** El formulario predeterminado (el de «Pedir acceso»). */
    public function predeterminado(Request $request, WaiversTenant $waivers): JsonResponse
    {
        return $this->presentar($request, $this->captacion->formularioPublico(null), $waivers);
    }

    public function mostrar(Request $request, WaiversTenant $waivers): JsonResponse
    {
        return $this->presentar($request, $this->captacion->formularioPublico((string) $request->route('formulario')), $waivers);
    }

    public function enviar(Request $request, VerificarRecaptcha $recaptcha): JsonResponse
    {
        $formulario = $this->captacion->formularioPublico((string) $request->route('formulario'));
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'respuestas' => ['nullable', 'array', 'max:30'],
            'interes' => ['nullable', 'array'],
            'interes.sucursal' => ['nullable', 'string', 'max:40'],
            'interes.actividad' => ['nullable', 'string', 'max:40'],
            'interes.sesion' => ['nullable', 'string', 'max:40'],
            'interes.producto' => ['nullable', 'string', 'max:40'],
            'fuente' => ['nullable', 'string', 'max:120'],
            'acepta_aviso' => ['accepted'],
            'aviso_version' => ['nullable', 'integer', 'min:1'],
            'recaptcha_token' => ['nullable', 'string', 'max:4000'],
        ]);

        if (! $recaptcha->aprobado((string) ($validado['recaptcha_token'] ?? ''), $request->ip())) {
            throw ValidationException::withMessages([
                'recaptcha' => ['No pudimos verificar que no eres un robot. Recarga e inténtalo de nuevo.'],
            ]);
        }

        $this->captacion->recibir($formulario, [
            'nombre' => (string) $validado['nombre'],
            'email' => (string) $validado['email'],
            'telefono' => $validado['telefono'] ?? null,
            'respuestas' => $validado['respuestas'] ?? null,
            'interes' => $validado['interes'] ?? null,
            'fuente' => $validado['fuente'] ?? null,
            'aviso_version' => isset($validado['aviso_version']) ? (int) $validado['aviso_version'] : null,
        ]);

        // La misma respuesta si ya lo conocíamos o es nuevo.
        return response()->json(['data' => [
            'mensaje' => $formulario->mensaje_confirmacion ?: 'Recibimos tus datos. Te contactaremos pronto.',
        ]], 201);
    }

    private function presentar(Request $request, FormularioCaptacionTenant $formulario, WaiversTenant $waivers): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        $aviso = $waivers->avisoPrivacidad();

        return response()->json(['data' => [
            'id' => $formulario->ulid,
            'titulo' => $formulario->titulo,
            'descripcion' => $formulario->descripcion,
            'pide_telefono' => $formulario->pide_telefono,
            'telefono_obligatorio' => $formulario->telefono_obligatorio,
            'campos' => $formulario->campos->map(fn (CampoFormularioCaptacionTenant $c): array => [
                'id' => $c->ulid,
                'etiqueta' => $c->etiqueta,
                'tipo' => $c->tipo->value,
                'obligatorio' => $c->obligatorio,
                'opciones' => $c->opciones,
            ])->values()->all(),
            // Sin aviso publicado, en producción no se reciben datos.
            'aviso' => $aviso !== null ? ['version' => (int) $aviso->version] : null,
            'negocio' => $estudio instanceof Estudio ? ['nombre' => $estudio->nombre, 'slug' => $estudio->slug] : null,
        ]]);
    }
}
