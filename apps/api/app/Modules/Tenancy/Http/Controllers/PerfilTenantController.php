<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\CambiarCorreoTenant;
use App\Modules\Tenancy\Application\PersonaDeUsuarioTenant;
use App\Modules\Tenancy\Http\Requests\DatosPersonales;
use App\Modules\Tenancy\Http\UsuarioTenantPresenter;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\TokenAccesoTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * "Mi perfil": lo que cada usuario ajusta de sí mismo (cualquier rol, sin permiso
 * extra): nombre, celular (el de su ficha de cliente o alumno), foto, contraseña y
 * correo (este último, confirmado por enlace).
 */
class PerfilTenantController
{
    public function __construct(
        private readonly CambiarCorreoTenant $cambioCorreo,
        private readonly PersonaDeUsuarioTenant $personas,
    ) {}

    public function actualizar(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        // El celular (para avisos y WhatsApp), la fecha de nacimiento y el género son de
        // su ficha de cliente o alumno: sin ficha no hay dónde guardarlos.
        $deFicha = $request->hasAny(['celular', 'fecha_nacimiento', 'genero']);
        $persona = $deFicha ? $this->personas->buscar($usuario) : null;
        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'primer_apellido' => ['nullable', 'string', 'max:80'],
            'segundo_apellido' => ['nullable', 'string', 'max:80'],
            'celular' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9 +()-]*$/',
                Rule::unique(PersonaTenant::class, 'celular')->whereNull('deleted_at')->ignore($persona?->getKey())],
            ...DatosPersonales::reglas(),
        ], [
            'celular.regex' => 'Escribe el celular solo con números.',
            'celular.unique' => 'Ese celular ya es de otra persona en este negocio.',
            ...DatosPersonales::mensajes(),
        ]);
        if ($persona instanceof PersonaTenant) {
            $deLaFicha = [];
            if (array_key_exists('celular', $validado)) {
                $celular = trim((string) $validado['celular']);
                $deLaFicha['celular'] = $celular !== '' ? $celular : null;
            }
            foreach (['fecha_nacimiento', 'genero'] as $campo) {
                if (array_key_exists($campo, $validado)) {
                    $deLaFicha[$campo] = $validado[$campo] !== '' ? $validado[$campo] : null;
                }
            }
            if ($deLaFicha !== []) {
                $persona->update($deLaFicha);
            }
        }

        $partes = array_map(
            static fn (?string $parte): ?string => $parte !== null && trim($parte) !== '' ? trim($parte) : null,
            [$validado['nombre'], $validado['primer_apellido'] ?? null, $validado['segundo_apellido'] ?? null],
        );

        $usuario->update([
            'nombre' => $partes[0],
            'primer_apellido' => $partes[1],
            'segundo_apellido' => $partes[2],
            // `name` sigue siendo el nombre completo (lo usan listados y correos).
            'name' => implode(' ', array_filter($partes)),
        ]);

        return $this->responder($usuario);
    }

    /**
     * Cambia la contraseña. Pide la actual (si ya tenía una) y cierra las demás
     * sesiones del usuario; la de este dispositivo sigue abierta.
     */
    public function cambiarContrasena(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        $tieneContrasena = $usuario->password !== null && $usuario->password !== '';

        $validado = $request->validate([
            'actual' => [$tieneContrasena ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if ($tieneContrasena && ! Hash::check((string) $validado['actual'], (string) $usuario->password)) {
            throw ValidationException::withMessages(['actual' => ['La contraseña actual no es correcta.']]);
        }

        $usuario->update(['password' => $validado['password']]);

        TokenAccesoTenant::query()
            ->where('tokenable_type', Usuario::class)
            ->where('tokenable_id', $usuario->getKey())
            ->whereKeyNot($this->tokenActual($request) ?? 0)
            ->delete();

        return $this->responder($usuario);
    }

    /**
     * Pide cambiar el correo de acceso: confirma con la contraseña actual (si ya
     * tiene una) y envía el enlace al correo nuevo. Hasta abrirlo, sigue el anterior.
     */
    public function solicitarCambioCorreo(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        $tieneContrasena = $usuario->password !== null && $usuario->password !== '';

        $validado = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => [$tieneContrasena ? 'required' : 'nullable', 'string'],
        ]);

        if ($tieneContrasena && ! Hash::check((string) $validado['password'], (string) $usuario->password)) {
            throw ValidationException::withMessages(['password' => ['La contraseña no es correcta.']]);
        }

        $this->cambioCorreo->solicitar($this->estudio($request), $usuario, (string) $validado['email']);

        return $this->responder($usuario);
    }

    public function cancelarCambioCorreo(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        $this->cambioCorreo->cancelar($usuario);

        return $this->responder($usuario);
    }

    public function subirFoto(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        $request->validate([
            // SVG excluido a propósito (riesgo de XSS al servirse en el navegador).
            'foto' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $archivo = $request->file('foto');
        // Carpeta propia por estudio (no la de su marca: cambiar el logo la vacía) y
        // nombre no enumerable.
        $ruta = $archivo->storeAs(
            'usuarios/'.$this->estudio($request)->getKey(),
            Str::lower(Str::random(40)).'.'.$archivo->extension(),
            'public',
        );

        $this->borrarFoto($usuario);
        $usuario->update(['foto_ruta' => $ruta]);

        return $this->responder($usuario);
    }

    public function eliminarFoto(Request $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        $this->borrarFoto($usuario);
        $usuario->update(['foto_ruta' => null]);

        return $this->responder($usuario);
    }

    private function borrarFoto(Usuario $usuario): void
    {
        if ($usuario->foto_ruta !== null && $usuario->foto_ruta !== '') {
            Storage::disk('public')->delete($usuario->foto_ruta);
        }
    }

    /**
     * Id del token con el que llegó esta petición (formato `id|secreto`).
     */
    private function tokenActual(Request $request): ?int
    {
        $valor = (string) $request->bearerToken();
        $id = explode('|', $valor, 2)[0];

        return ctype_digit($id) ? (int) $id : null;
    }

    private function responder(Usuario $usuario): JsonResponse
    {
        return response()->json(['data' => ['usuario' => UsuarioTenantPresenter::datos($usuario->refresh())]]);
    }

    private function usuario(Request $request): Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');
        abort_unless($usuario instanceof Usuario, 401);

        return $usuario;
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
    }
}
