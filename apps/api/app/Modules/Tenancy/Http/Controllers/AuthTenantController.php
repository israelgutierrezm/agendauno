<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ActivacionPropietario;
use App\Modules\Tenancy\Application\AutenticacionGoogleTenant;
use App\Modules\Tenancy\Application\AutenticacionTenant;
use App\Modules\Tenancy\Application\CambiarCorreoTenant;
use App\Modules\Tenancy\Application\EnviarActivacionTenant;
use App\Modules\Tenancy\Application\FechasNegocioTenant;
use App\Modules\Tenancy\Application\FuncionesPlan;
use App\Modules\Tenancy\Application\ParametrosTenant;
use App\Modules\Tenancy\Application\RegionNegocioTenant;
use App\Modules\Tenancy\Application\RestablecerContrasenaTenant;
use App\Modules\Tenancy\Application\RolesTenant;
use App\Modules\Tenancy\Application\WhatsAppTenant;
use App\Modules\Tenancy\Http\Requests\ActivarTenantRequest;
use App\Modules\Tenancy\Http\Requests\LoginTenantRequest;
use App\Modules\Tenancy\Http\UsuarioTenantPresenter;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
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
        private readonly RestablecerContrasenaTenant $restablecimiento,
        private readonly CambiarCorreoTenant $cambioCorreo,
    ) {}

    /**
     * Pide el enlace para elegir una contraseña nueva. Público y SIN enumeración:
     * responde igual exista o no la cuenta.
     */
    public function recuperarContrasena(Request $request): JsonResponse
    {
        $validado = $request->validate(['email' => ['required', 'email']]);
        $this->restablecimiento->solicitar($this->estudioDe($request), (string) $validado['email']);

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * Fija la contraseña nueva con el enlace del correo y deja la sesión iniciada
     * (las demás sesiones de la cuenta se cierran).
     */
    /**
     * Aplica el correo nuevo desde el enlace que le llegó (no requiere sesión).
     */
    public function confirmarCorreo(Request $request): JsonResponse
    {
        $validado = $request->validate(['token' => ['required', 'string', 'max:100']]);

        $usuario = $this->cambioCorreo->confirmar($this->estudioDe($request), (string) $validado['token']);

        return response()->json(['data' => ['email' => $usuario->email]]);
    }

    public function restablecerContrasena(ActivarTenantRequest $request): JsonResponse
    {
        $usuario = $this->restablecimiento->restablecer(
            (string) $request->validated('email'),
            (string) $request->validated('token'),
            (string) $request->validated('password'),
        );

        return response()->json(['data' => [
            'token' => $this->auth->emitir($usuario),
            'usuario' => UsuarioTenantPresenter::datos($usuario),
            'estudio' => $this->presentarEstudio($this->estudioDe($request)),
        ]]);
    }

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
            'token' => $this->auth->emitir($usuario, rol: $this->rolSiSuspendido($estudio, $usuario)),
            'usuario' => UsuarioTenantPresenter::datos($usuario),
            'estudio' => $this->presentarEstudio($estudio),
        ]]);
    }

    public function google(Request $request): JsonResponse
    {
        $estudio = $this->estudioDe($request);

        $validado = $request->validate(['credential' => ['required', 'string']]);

        $usuario = $this->google->ejecutar((string) $validado['credential']);

        return response()->json(['data' => [
            'token' => $this->auth->emitir($usuario, rol: $this->rolSiSuspendido($estudio, $usuario)),
            'usuario' => UsuarioTenantPresenter::datos($usuario),
            'estudio' => $this->presentarEstudio($estudio),
        ]]);
    }

    /**
     * Conecta Google a la cuenta con la que se inició sesión (ADR 0093): desde ese
     * momento puede entrar con Google. Sin registro: solo cuentas que ya existen.
     */
    public function conectarGoogle(Request $request): JsonResponse
    {
        $usuario = $this->usuarioTenant($request);
        abort_unless($usuario instanceof Usuario, 401);
        $validado = $request->validate(['credential' => ['required', 'string']]);

        $this->google->conectar($usuario, (string) $validado['credential']);

        return response()->json(['data' => ['usuario' => UsuarioTenantPresenter::datos($usuario->refresh())]]);
    }

    public function desconectarGoogle(Request $request): JsonResponse
    {
        $usuario = $this->usuarioTenant($request);
        abort_unless($usuario instanceof Usuario, 401);

        $this->google->desconectar($usuario);

        return response()->json(['data' => ['usuario' => UsuarioTenantPresenter::datos($usuario->refresh())]]);
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
            'usuario' => UsuarioTenantPresenter::datos($usuario),
            'estudio' => $this->presentarEstudio($estudio),
        ]], 201);
    }

    public function yo(Request $request): JsonResponse
    {
        $usuario = $this->usuarioTenant($request);
        abort_unless($usuario instanceof Usuario, 401);

        return response()->json(['data' => [
            'usuario' => UsuarioTenantPresenter::datos($usuario),
            'estudio' => $this->presentarEstudio($this->estudioDe($request)),
            // La versión más antigua de la app que aún se acepta (ADR 0104): una más
            // vieja pide actualizar en lugar de leer contratos que ya no entiende.
            'app' => ['version_minima' => (string) config('agendauno.app.version_minima', '0.0.0')],
        ]]);
    }

    /**
     * Cambia el rol con el que se trabaja en esta sesión (solo a uno que la persona
     * tiene). Desde ese momento la API solo concede los permisos de ese rol; también
     * queda como el de la última vez.
     */
    public function rolActivo(Request $request): JsonResponse
    {
        $usuario = $this->usuarioTenant($request);
        abort_unless($usuario instanceof Usuario, 401);
        $validado = $request->validate(['rol' => ['required', 'string', 'max:40']]);

        if (! $this->auth->cambiarRol((string) $request->bearerToken(), $usuario, (string) $validado['rol'])) {
            throw ValidationException::withMessages(['rol' => ['No tienes ese rol en este negocio.']]);
        }

        return response()->json(['data' => [
            'usuario' => UsuarioTenantPresenter::datos($usuario),
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

    /**
     * Suspendido por renta (ADR 0073): solo entra quien puede ver la facturación, para
     * pagarla, y entra con ese rol. Los demás ven que el negocio está suspendido.
     */
    private function rolSiSuspendido(Estudio $estudio, Usuario $usuario): ?string
    {
        if (! $estudio->suspendidoPorRenta()) {
            return null;
        }

        $roles = app(RolesTenant::class);
        foreach ($usuario->rolesEfectivos() as $rol) {
            if ($roles->puedeAlguno([$rol], 'facturacion.ver')) {
                return $rol;
            }
        }

        throw ValidationException::withMessages(['email' => ['Este negocio está suspendido por ahora. Vuelve a intentarlo más tarde.']]);
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
            // Solo clases o solo citas (ADR 0104): la web y la app preguntan por la
            // capacidad que manda el servidor, no la deducen del giro ni de las ofertas.
            'modalidad' => $estudio->modalidad()->value,
            'capacidades' => $estudio->modalidad()->capacidades(),
            // Manda avisos por WhatsApp a sus clientes (ADR 0069).
            'whatsapp_clientes' => app(WhatsAppTenant::class)->enUso(),
            // Su moneda y su zona horaria (ADR 0099); con pesos mexicanos cobra en
            // línea y (en México) factura.
            'moneda' => app(ParametrosTenant::class)->moneda(),
            'zona_horaria' => app(FechasNegocioTenant::class)->zona(),
            // Su país y su lada (ADR 0103): la de los celulares que se capturan sin «+».
            'pais' => app(RegionNegocioTenant::class)->pais(),
            'lada' => app(RegionNegocioTenant::class)->lada(),
            'cobra_en_linea_posible' => app(RegionNegocioTenant::class)->enPesos(),
            'factura_posible' => app(RegionNegocioTenant::class)->factura(),
            // Wellhub y TotalPass solo operan en México.
            'bienestar_posible' => app(RegionNegocioTenant::class)->enMexico(),
            // ¿La plataforma ya factura? En producción, solo con la llave de FacturAPI.
            'facturacion_disponible' => ConfiguracionPlataforma::facturacionDisponible(),
            // Su plan (ADR 0107): con el nivel de un negocio de citas, las funciones que no
            // tiene (la web y la app las ocultan; el servidor ya las niega).
            'plan' => [
                'nivel' => app(FuncionesPlan::class)->nivel($estudio),
                'sin' => app(FuncionesPlan::class)->faltantes($estudio),
            ],
        ];
    }
}
