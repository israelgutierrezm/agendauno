<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AprovisionarEstudio;
use App\Modules\Tenancy\Application\EnviarActivacionTenant;
use App\Modules\Tenancy\Application\RegistrarEstudio;
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
    ) {}

    public function disponibilidad(Request $request): JsonResponse
    {
        $slug = Str::slug((string) $request->query('slug', ''));
        $disponible = $slug !== '' && ! Estudio::query()->where('slug', $slug)->exists();

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
