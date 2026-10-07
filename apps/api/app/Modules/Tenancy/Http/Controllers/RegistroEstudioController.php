<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Platform\Legales\DocumentoLegal;
use App\Modules\Platform\Legales\DocumentosLegales;
use App\Modules\Tenancy\Application\AprovisionarEstudio;
use App\Modules\Tenancy\Application\EnviarActivacionTenant;
use App\Modules\Tenancy\Application\RegistrarEstudio;
use App\Modules\Tenancy\Application\VerificacionWhatsAppDueno;
use App\Modules\Tenancy\Application\VerificarRecaptcha;
use App\Modules\Tenancy\Http\Requests\RegistrarEstudioRequest;
use App\Modules\Tenancy\Models\Estudio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Alta pública de un estudio (self-service). Crea el registro central, aprovisiona
 * su BD de tenant y su propietario tenant-local, y devuelve el enlace de acceso.
 * El token de activación se envía por correo en producción (aquí se retorna solo
 * fuera de producción para poder probar el flujo).
 */
class RegistroEstudioController
{
    public function __construct(
        private readonly RegistrarEstudio $registrar,
        private readonly AprovisionarEstudio $aprovisionar,
        private readonly EnviarActivacionTenant $enviarActivacion,
        private readonly VerificarRecaptcha $recaptcha,
        private readonly DocumentosLegales $legales,
        private readonly VerificacionWhatsAppDueno $whatsapp,
    ) {}

    public function disponibilidad(Request $request): JsonResponse
    {
        $slug = Str::slug((string) $request->query('slug', ''));
        // Uno más largo de lo que acepta el registro tampoco está disponible.
        $disponible = $slug !== '' && strlen($slug) <= RegistrarEstudio::LARGO_MAXIMO_SLUG
            && ! Estudio::query()->where('slug', $slug)->exists();

        return response()->json(['data' => ['slug' => $slug, 'disponible' => $disponible]]);
    }

    public function store(RegistrarEstudioRequest $request): JsonResponse
    {
        // Anti-bots: reCAPTCHA v3 (se omite si no hay llaves configuradas).
        if (! $this->recaptcha->aprobado($request->string('recaptcha_token')->value(), $request->ip())) {
            throw ValidationException::withMessages([
                'recaptcha' => ['No pudimos verificar que no eres un robot. Recarga e inténtalo de nuevo.'],
            ]);
        }

        // En producción no se registra nadie sin un aviso de privacidad y unos
        // términos publicados; y lo que se acepta es lo que se leyó.
        $aviso = $this->legales->vigente(DocumentoLegal::AVISO);
        $terminos = $this->legales->vigente(DocumentoLegal::TERMINOS);
        if (app()->environment('production') && ($aviso === null || $terminos === null)) {
            throw ValidationException::withMessages([
                'acepta_terminos' => ['El registro abrirá cuando el aviso de privacidad y los términos estén publicados.'],
            ]);
        }
        foreach (['aviso_version' => $aviso, 'terminos_version' => $terminos] as $campo => $vigente) {
            $leida = $request->validated($campo);
            if ($leida !== null && $vigente !== null && (int) $leida !== $vigente->version) {
                throw ValidationException::withMessages([
                    'acepta_terminos' => ['El aviso de privacidad o los términos cambiaron mientras te registrabas: revísalos y vuelve a aceptarlos.'],
                ]);
            }
        }

        // Confirmó su WhatsApp (ADR 0070): el comprobante es de ese número y sigue vigente.
        $verificacion = null;
        $comprobante = $request->validated('whatsapp_verificacion');
        if (is_string($comprobante) && $comprobante !== '') {
            $telefono = VerificacionWhatsAppDueno::telefono((string) $request->validated('contacto_whatsapp_pais'), (string) $request->validated('contacto_telefono'));
            $verificacion = $telefono !== null ? $this->whatsapp->comprobanteValido($telefono, $comprobante) : null;
            if ($verificacion === null) {
                throw ValidationException::withMessages([
                    'whatsapp_verificacion' => ['La verificación de tu WhatsApp venció. Vuelve a verificar tu número.'],
                ]);
            }
        }

        $estudio = $this->registrar->ejecutar([
            'nombre' => (string) $request->validated('nombre'),
            'slug' => (string) $request->validated('slug'),
            'perfil_negocio' => $request->validated('perfil_negocio'),
            'contacto_nombre' => (string) $request->validated('contacto_nombre'),
            'contacto_segundo_nombre' => $request->validated('contacto_segundo_nombre'),
            'contacto_primer_apellido' => (string) $request->validated('contacto_primer_apellido'),
            'contacto_segundo_apellido' => $request->validated('contacto_segundo_apellido'),
            'contacto_email' => (string) $request->validated('contacto_email'),
            'contacto_whatsapp_pais' => (string) $request->validated('contacto_whatsapp_pais'),
            'contacto_telefono' => $request->validated('contacto_telefono'),
            'pais' => $request->validated('pais'),
            'ciudad' => $request->validated('ciudad'),
            'zona_horaria' => $request->validated('zona_horaria'),
        ]);

        $this->legales->registrarAceptacion($estudio, (string) $estudio->contacto_email, $request->ip(), $request->userAgent());
        if ($verificacion !== null) {
            $this->whatsapp->usar($verificacion);
            $estudio->forceFill([
                'contacto_whatsapp_verificado_en' => now(),
                'contacto_whatsapp_aceptado_en' => now(),
            ])->save();
        }

        // BD del tenant creada de forma síncrona (SQLite barato). En producción con
        // MySQL esto se despacharía a una cola; el estado permite reanudar.
        $this->aprovisionar->ejecutar($estudio);
        // Genera el token y ENVÍA el correo de activación (cierra el alta autónoma).
        $token = $this->enviarActivacion->enviar($estudio, (string) $estudio->contacto_email);

        return response()->json([
            'data' => [
                'estudio' => [
                    'slug' => $estudio->slug,
                    'nombre' => $estudio->nombre,
                    'perfil' => $estudio->perfil_negocio->value,
                    // Queda guardada desde su giro (ADR 0104).
                    'modalidad' => $estudio->modalidad()->value,
                    'estado' => $estudio->estado->value,
                    'url' => url('/app/'.$estudio->slug),
                ],
                'activacion' => app()->environment('production') ? null : [
                    'email' => $estudio->contacto_email,
                    'token' => $token,
                ],
            ],
        ], 201);
    }
}
