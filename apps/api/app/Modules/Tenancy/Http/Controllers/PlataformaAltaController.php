<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AprovisionarEstudio;
use App\Modules\Tenancy\Application\AprovisionarNegocioRegistrado;
use App\Modules\Tenancy\Application\EnviarActivacionTenant;
use App\Modules\Tenancy\Application\RegistrarEstudio;
use App\Modules\Tenancy\CatalogoPaises;
use App\Modules\Tenancy\Database\GestorDeConexionTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\PerfilNegocio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Soporte desde el superadmin (ADR 0226): dar de alta un negocio a nombre de su dueño
 * (alta asistida, también con el registro cerrado) y corregir el contacto del dueño. El
 * dueño activa su cuenta con el correo de siempre y, al entrar, acepta el aviso y los
 * términos (no los aceptó al registrarse). Todo queda en la bitácora del superadmin.
 */
class PlataformaAltaController
{
    public function crear(Request $request, RegistrarEstudio $registrar, AprovisionarEstudio $aprovisionar, EnviarActivacionTenant $activacion): JsonResponse
    {
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'min:3', 'max:'.RegistrarEstudio::LARGO_MAXIMO_SLUG, 'regex:/^[a-z0-9-]+$/', 'unique:estudios,slug', Rule::notIn(RegistrarEstudio::SLUGS_RESERVADOS)],
            'perfil_negocio' => ['required', Rule::enum(PerfilNegocio::class)],
            ...self::reglasContacto(),
            'pais' => ['required', 'string', Rule::in(CatalogoPaises::codigos())],
            'ciudad' => ['nullable', 'string', 'max:120'],
            'zona_horaria' => ['nullable', 'timezone'],
        ]);

        $estudio = $registrar->ejecutar([
            'nombre' => (string) $validado['nombre'],
            'slug' => (string) ($validado['slug'] ?? ''),
            'perfil_negocio' => $validado['perfil_negocio'],
            'contacto_nombre' => (string) $validado['contacto_nombre'],
            'contacto_segundo_nombre' => $validado['contacto_segundo_nombre'] ?? null,
            'contacto_primer_apellido' => (string) $validado['contacto_primer_apellido'],
            'contacto_segundo_apellido' => $validado['contacto_segundo_apellido'] ?? null,
            'contacto_email' => (string) $validado['contacto_email'],
            'contacto_whatsapp_pais' => (string) $validado['contacto_whatsapp_pais'],
            'contacto_telefono' => $validado['contacto_telefono'],
            'pais' => $validado['pais'],
            'ciudad' => $validado['ciudad'] ?? null,
            'zona_horaria' => $validado['zona_horaria'] ?? null,
        ]);

        // Como el registro (ADR 0222): en producción, su base en la cola y la activación al
        // terminar; en desarrollo y pruebas, en línea.
        if ((bool) config('agendauno.plataforma.aprovisionar_en_cola')) {
            AprovisionarNegocioRegistrado::dispatch((int) $estudio->getKey());
        } else {
            $aprovisionar->ejecutar($estudio);
            $activacion->enviar($estudio, (string) $estudio->contacto_email);
        }

        return response()->json(['data' => PlataformaEstudiosController::resumen($estudio->refresh())], 201);
    }

    /**
     * Corrige el nombre, el correo o el WhatsApp del dueño. El correo, solo mientras no
     * haya activado su cuenta (es con el que entra; después lo cambia él desde su perfil):
     * se le cambia también en su negocio y le llega un enlace de activación nuevo.
     */
    public function contacto(Request $request, GestorDeConexionTenant $gestor, EnviarActivacionTenant $activacion): JsonResponse
    {
        $modelo = Estudio::query()->where('slug', (string) $request->route('estudio'))->firstOrFail();
        $validado = $request->validate(self::reglasContacto());
        $anterior = strtolower((string) $modelo->contacto_email);
        $nuevo = strtolower(trim((string) $validado['contacto_email']));

        $cambiaCorreo = $nuevo !== $anterior;
        if ($cambiaCorreo && $gestor->baseDeDatosExiste($modelo)) {
            $gestor->ejecutarEn($modelo, function () use ($anterior, $nuevo): void {
                $dueno = Usuario::query()->where('email', $anterior)->first();
                if ($dueno instanceof Usuario && $dueno->activo) {
                    throw ValidationException::withMessages(['contacto_email' => ['El dueño ya activó su cuenta: el correo con que entra lo cambia él desde su perfil.']]);
                }
                if (Usuario::query()->where('email', $nuevo)->exists()) {
                    throw ValidationException::withMessages(['contacto_email' => ['Ese correo ya es de otra cuenta de este negocio.']]);
                }
                $dueno?->update(['email' => $nuevo]);
            });
        }

        $modelo->update([
            'contacto_nombre' => (string) $validado['contacto_nombre'],
            'contacto_segundo_nombre' => $validado['contacto_segundo_nombre'] ?? null,
            'contacto_primer_apellido' => (string) $validado['contacto_primer_apellido'],
            'contacto_segundo_apellido' => $validado['contacto_segundo_apellido'] ?? null,
            'contacto_email' => $nuevo,
            'contacto_whatsapp_pais' => (string) $validado['contacto_whatsapp_pais'],
            'contacto_telefono' => (string) $validado['contacto_telefono'],
        ]);
        if ($cambiaCorreo && $gestor->baseDeDatosExiste($modelo)) {
            $activacion->enviar($modelo->refresh(), $nuevo);
        }

        return response()->json(['data' => PlataformaEstudiosController::resumen($modelo->refresh())]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function reglasContacto(): array
    {
        return [
            'contacto_nombre' => ['required', 'string', 'max:120'],
            'contacto_segundo_nombre' => ['nullable', 'string', 'max:120'],
            'contacto_primer_apellido' => ['required', 'string', 'max:120'],
            'contacto_segundo_apellido' => ['nullable', 'string', 'max:120'],
            'contacto_whatsapp_pais' => ['required', 'string', 'regex:/^\d{1,4}$/'],
            'contacto_telefono' => ['required', 'string', 'regex:/^[0-9 \-]{7,15}$/'],
            'contacto_email' => ['required', 'email', 'max:255'],
        ];
    }
}
