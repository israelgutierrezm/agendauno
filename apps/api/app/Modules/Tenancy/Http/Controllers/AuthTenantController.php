<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ActivacionPropietario;
use App\Modules\Tenancy\Application\AutenticacionGoogleTenant;
use App\Modules\Tenancy\Application\AutenticacionTenant;
use App\Modules\Tenancy\Application\CatalogoDePermisosTenant;
use App\Modules\Tenancy\Application\EnviarActivacionTenant;
use App\Modules\Tenancy\Http\Requests\ActivarTenantRequest;
use App\Modules\Tenancy\Http\Requests\LoginTenantRequest;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Autenticación tenant-local. Todas las rutas van detrás de ResolverEstudio, por
 * lo que las consultas de `Usuario` y la emisión/revocación de tokens ocurren en
 * la BD del estudio ya resuelto. Un token de otro estudio no autentica aquí.
 */
class AuthTenantController
{
    public function __construct(
        private readonly AutenticacionTenant $auth,
        private readonly ActivacionPropietario $activacion,
        private readonly AutenticacionGoogleTenant $google,
        private readonly EnviarActivacionTenant $enviarActivacion,
    ) {}

    /**
     * Reenvía el correo de activación al propietario/usuario que aún no activa su
     * cuenta. Público (aún no puede iniciar sesión) y SIN enumeración: responde igual
     * exista o no la cuenta.
     */
    public function reenviarActivacion(Request $request): JsonResponse
    {
        $estudio = $this->estudioDe($request);
        $validado = $request->validate(['email' => ['required', 'email']]);

        $usuario = Usuario::query()->where('email', (string) $validado['email'])->first();
        if ($usuario instanceof Usuario && ! $usuario->activo) {
            $this->enviarActivacion->enviar($estudio, (string) $usuario->email);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    public function store(LoginTenantRequest $request): JsonResponse
    {
        $estudio = $this->estudioDe($request);

        $usuario = Usuario::query()->where('email', (string) $request->validated('email'))->first();

        if (! $usuario instanceof Usuario
            || ! $usuario->activo
            || $usuario->password === null
            || ! Hash::check((string) $request->validated('password'), (string) $usuario->password)) {
            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        return response()->json(['data' => [
            'token' => $this->auth->emitir($usuario),
            'usuario' => $this->presentarUsuario($usuario),
            'estudio' => $this->presentarEstudio($estudio),
        ]]);
    }

    public function google(Request $request): JsonResponse
    {
        $estudio = $this->estudioDe($request);

        $validado = $request->validate(['credential' => ['required', 'string']]);

        $usuario = $this->google->ejecutar((string) $validado['credential']);

        return response()->json(['data' => [
            'token' => $this->auth->emitir($usuario),
            'usuario' => $this->presentarUsuario($usuario),
            'estudio' => $this->presentarEstudio($estudio),
        ]]);
    }

    public function activar(ActivarTenantRequest $request): JsonResponse
    {
        $estudio = $this->estudioDe($request);

        $usuario = $this->activacion->activar(
            $estudio,
            (string) $request->validated('email'),
            (string) $request->validated('token'),
            (string) $request->validated('password'),
        );

        return response()->json(['data' => [
            'token' => $this->auth->emitir($usuario),
            'usuario' => $this->presentarUsuario($usuario),
            'estudio' => $this->presentarEstudio($estudio),
        ]], 201);
    }

    public function yo(Request $request): JsonResponse
    {
        $usuario = $this->usuarioTenant($request);
        abort_unless($usuario instanceof Usuario, 401);

        return response()->json(['data' => [
            'usuario' => $this->presentarUsuario($usuario),
            'estudio' => $this->presentarEstudio($this->estudioDe($request)),
        ]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $usuario = $this->usuarioTenant($request);
        if ($usuario instanceof Usuario) {
            $this->auth->revocarTodos($usuario);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    private function usuarioTenant(Request $request): ?Usuario
    {
        $usuario = $request->attributes->get('usuario_tenant');

        return $usuario instanceof Usuario ? $usuario : null;
    }

    private function estudioDe(Request $request): Estudio
    {
        $estudio = $request->attributes->get('estudio');
        abort_unless($estudio instanceof Estudio, 404);

        return $estudio;
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
            // Perfil de negocio (R35): el frontend adapta terminologia/flags sin forks.
            'perfil' => $estudio->perfil_negocio->value,
            'perfil_config' => $estudio->perfilConfig(),
        ];
    }
}
