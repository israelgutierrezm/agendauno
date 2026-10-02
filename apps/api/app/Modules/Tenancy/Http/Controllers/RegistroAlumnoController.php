<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\AutenticacionTenant;
use App\Modules\Tenancy\Application\RegistrarAlumnoTenant;
use App\Modules\Tenancy\Application\RegistroDeAlumno;
use App\Modules\Tenancy\Application\RolesTenant;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\PersonaTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registro PÚBLICO de un alumno en un estudio (embudo público, P0 #3): un prospecto
 * crea su cuenta desde el escaparate y queda dentro (auto-login), con un token igual
 * que el login. Si su correo ya es de alguien en el negocio (una ficha o una cuenta
 * dada de baja), primero lo confirma desde ese correo ({@see RegistrarAlumnoTenant}).
 * Solo con la página pública abierta (aunque no esté en el directorio); email único por estudio. Sin auth;
 * con throttle.
 */
class RegistroAlumnoController
{
    public function __construct(
        private readonly AutenticacionTenant $auth,
        private readonly RegistrarAlumnoTenant $registro,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);

        $validado = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'primer_apellido' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $resultado = $this->registro->registrar($estudio, [
            'nombre' => (string) $validado['nombre'],
            'primer_apellido' => isset($validado['primer_apellido']) ? (string) $validado['primer_apellido'] : null,
            'email' => (string) $validado['email'],
            'password' => (string) $validado['password'],
        ]);

        if ($resultado->usuario === null) {
            // Se le mandó el enlace a su correo; entra al confirmarlo.
            return response()->json(['data' => [
                'confirmacion' => 'enviada',
                'email' => $resultado->emailPorConfirmar,
            ]], 202);
        }

        return $this->conSesion($estudio, $resultado);
    }

    /**
     * Abre el enlace del correo de confirmación: liga su historial y entra.
     */
    public function confirmar(Request $request): JsonResponse
    {
        $estudio = $this->estudio($request);
        $validado = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
        ]);

        return $this->conSesion($estudio, $this->registro->confirmar($estudio, (string) $validado['email'], (string) $validado['token']));
    }

    private function estudio(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);
        // El registro público solo existe con la página pública abierta.
        abort_unless($estudio->paginaPublica(), 404);

        return $estudio;
    }

    private function conSesion(Estudio $estudio, RegistroDeAlumno $resultado): JsonResponse
    {
        $usuario = $resultado->usuario;
        $persona = $resultado->persona;
        abort_unless($usuario instanceof Usuario && $persona instanceof PersonaTenant, 500);

        return response()->json(['data' => [
            'token' => $this->auth->emitir($usuario),
            'usuario' => $this->presentarUsuario($usuario),
            'estudio' => $this->presentarEstudio($estudio),
            'persona_id' => (string) $persona->ulid,
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
            'rol' => $usuario->rolActivo() ?? app(RolesTenant::class)->principal($roles),
            'roles' => $roles,
            'roles_disponibles' => app(RolesTenant::class)->disponibles($roles),
            'permisos' => app(RolesTenant::class)->permisosDe($usuario->rolesVigentes()),
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
            'perfil_config' => $estudio->perfilConfig(),
        ];
    }
}
