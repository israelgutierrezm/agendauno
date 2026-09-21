<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AutenticacionTenant;
use App\Modules\Tenancy\Application\CatalogoDePermisosTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\TipoPersonaTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registro PÚBLICO de un alumno en un estudio (embudo público, P0 #3): un prospecto
 * crea su cuenta desde el escaparate y queda dentro (auto-login). Crea el usuario
 * tenant-local (rol `miembro`, activo, con contraseña) y su persona (alumno),
 * enlazados por `usuario_id`, y emite un token igual que el login. Solo para estudios
 * listados en el directorio; email único por estudio. Sin auth; con throttle.
 */
class RegistroAlumnoController
{
    public function __construct(private readonly AutenticacionTenant $auth) {}

    public function __invoke(Request $request): JsonResponse
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        // El registro público solo existe para estudios listados en el directorio.
        abort_unless($estudio->enDirectorio(), 404);

        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'primer_apellido' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $email = (string) $validado['email'];

        // Email único dentro de la BD del estudio (no enumeramos otros estudios).
        if (Usuario::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Ya existe una cuenta con ese correo en este estudio. Inicia sesión.'],
            ]);
        }

        $nombre = (string) $validado['nombre'];
        $primerApellidoRaw = $validado['primer_apellido'] ?? null;
        $primerApellido = $primerApellidoRaw !== null ? (string) $primerApellidoRaw : null;
        $nombreCompleto = trim($nombre.' '.($primerApellido ?? ''));

        $usuario = DB::connection('tenant')->transaction(function () use ($nombre, $primerApellido, $email, $validado, $nombreCompleto): Usuario {
            $usuario = Usuario::query()->create([
                'name' => $nombreCompleto !== '' ? $nombreCompleto : $nombre,
                'email' => $email,
                'password' => (string) $validado['password'],
                'activo' => true,
                'rol' => 'miembro',
                'roles' => ['miembro'],
            ]);

            PersonaTenant::query()->create([
                'nombre' => $nombre,
                'primer_apellido' => $primerApellido,
                'email' => $email,
                'tipo' => TipoPersonaTenant::Miembro->value,
                'activo' => true,
                'es_facturable' => true,
                'archivado' => false,
                'usuario_id' => $usuario->getKey(),
            ]);

            return $usuario;
        });

        return response()->json(['data' => [
            'token' => $this->auth->emitir($usuario),
            'usuario' => $this->presentarUsuario($usuario),
            'estudio' => $this->presentarEstudio($estudio),
        ]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarUsuario(Usuario $usuario): array
    {
        $roles = $usuario->rolesEfectivos();

        return [
            'ulid' => $usuario->ulid,
            'nombre' => $usuario->name,
            'email' => $usuario->email,
            'rol' => CatalogoDePermisosTenant::rolPrincipal($roles),
            'roles' => $roles,
            'permisos' => CatalogoDePermisosTenant::permisosDe($roles),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarEstudio(Estudio $estudio): array
    {
        return [
            'slug' => $estudio->slug,
            'nombre' => $estudio->nombre,
            'logo_url' => $estudio->logo_url,
            'estado' => $estudio->estado->value,
            'estado_facturacion' => $estudio->estado_facturacion->value,
            'trial_termina_en' => $estudio->trial_termina_en?->toDateString(),
            'publicado' => $estudio->publicado,
            'en_directorio' => $estudio->enDirectorio(),
            'perfil' => $estudio->perfil_negocio->value,
            'perfil_config' => $estudio->perfil_negocio->configuracion(),
        ];
    }
}
