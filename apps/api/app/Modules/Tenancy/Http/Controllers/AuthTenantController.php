<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Application\ActivacionPropietario;
use App\Modules\Tenancy\Application\AutenticacionGoogleTenant;
use App\Modules\Tenancy\Application\AutenticacionTenant;
use App\Modules\Tenancy\Application\BajaDeNegocio;
use App\Modules\Tenancy\Application\CambiarCorreoTenant;
use App\Modules\Tenancy\Application\EliminarCuentaPropiaTenant;
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
use App\Modules\Tenancy\Integraciones\RegistroDeIntegracionesTenant;
use App\Modules\Tenancy\ModalidadServicio;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\Estudio;
use App\Modules\Tenancy\Models\Usuario;
use App\Modules\Tenancy\Models\VisitantePlataformaTenant;
use App\Modules\Tenancy\ProveedorPartner;
use App\Modules\Tenancy\Support\MarcaProducto;
use Carbon\CarbonImmutable;
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

    /** Un hash con el costo de los reales, para correos sin cuenta (se calcula una vez). */
    private static function hashDeRelleno(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('relleno-sin-cuenta');
    }

    public function store(LoginTenantRequest $request): JsonResponse
    {
        $estudio = $this->estudioDe($request);

        $usuario = Usuario::query()->where('email', (string) $request->validated('email'))->first();

        // Se compara siempre contra un hash (el suyo o uno de relleno): el tiempo de la
        // respuesta no dice si el correo tiene cuenta.
        $hash = $usuario instanceof Usuario && $usuario->password !== null ? (string) $usuario->password : self::hashDeRelleno();
        $coincide = Hash::check((string) $request->validated('password'), $hash);
        if (! $usuario instanceof Usuario || ! $usuario->activo || $usuario->password === null || ! $coincide) {
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

    /**
     * Elimina la cuenta propia (ADR 0211), confirmando con la contraseña: la piden
     * Apple y Google para cualquier rol. Quien entra solo con Google crea antes una
     * contraseña con «¿Olvidaste tu contraseña?».
     */
    public function eliminarCuenta(Request $request, EliminarCuentaPropiaTenant $eliminar): JsonResponse
    {
        $usuario = $this->usuarioTenant($request);
        abort_unless($usuario instanceof Usuario, 401);
        $validado = $request->validate([
            'password' => ['required', 'string', 'max:255'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);
        if ($usuario->password === null) {
            throw ValidationException::withMessages(['password' => ['Entras con Google y aún no tienes contraseña: créala con «¿Olvidaste tu contraseña?» y vuelve a intentarlo.']]);
        }
        if (! Hash::check((string) $validado['password'], (string) $usuario->password)) {
            throw ValidationException::withMessages(['password' => ['La contraseña no coincide.']]);
        }
        $motivo = trim((string) ($validado['motivo'] ?? ''));
        $estado = $eliminar->ejecutar($usuario, $motivo !== '' ? $motivo : null);

        return response()->json(['data' => [
            'estado' => $estado,
            'mensaje' => $estado === EliminarCuentaPropiaTenant::SOLICITADA
                ? 'Tu cuenta quedó eliminada y ya no puedes entrar. El negocio borra tus datos en el plazo que marca la ley y solo conserva lo que está obligado a guardar (pagos y facturas).'
                : 'Tu cuenta quedó eliminada y ya no puedes entrar.',
        ]]);
    }

    /**
     * Un enlace de un solo uso para abrir la web con esta sesión (ADR 0211): desde la
     * app, lo que se hace en la web sin volver a escribir la contraseña. El destino es
     * una ruta de la web (nunca otro sitio).
     */
    public function enlaceWeb(Request $request): JsonResponse
    {
        $usuario = $this->usuarioTenant($request);
        abort_unless($usuario instanceof Usuario, 401);
        $validado = $request->validate([
            // Una ruta de la web: empieza con una sola «/» (no «//otro.sitio» ni «/\»).
            'destino' => ['required', 'string', 'max:300', 'regex:#^/(?![/\\\\])\S*$#'],
        ]);
        $estudio = $this->estudioDe($request);
        $codigo = $this->auth->crearEnlaceWeb((string) $estudio->slug, $usuario, (string) $validado['destino']);

        return response()->json(['data' => [
            'url' => MarcaProducto::urlWeb($estudio).'/entrar?'.http_build_query(['estudio' => $estudio->slug, 'enlace' => $codigo]),
            'vence_en' => now()->addMinutes(AutenticacionTenant::MINUTOS_ENLACE_WEB)->toIso8601String(),
        ]], 201);
    }

    /**
     * Canjea el enlace de un solo uso por una sesión de la web, con el rol con que
     * estaba en la app y a dónde iba.
     */
    public function canjearEnlaceWeb(Request $request): JsonResponse
    {
        $estudio = $this->estudioDe($request);
        $validado = $request->validate(['codigo' => ['required', 'string', 'max:100']]);
        $canje = $this->auth->canjearEnlaceWeb((string) $estudio->slug, (string) $validado['codigo']);
        if ($canje === null) {
            throw ValidationException::withMessages(['codigo' => ['El enlace ya se usó o venció. Ábrelo otra vez desde la app.']]);
        }

        return response()->json(['data' => [
            'token' => $this->auth->emitir($canje['usuario'], 'web', $canje['rol']),
            'usuario' => UsuarioTenantPresenter::datos($canje['usuario']),
            'estudio' => $this->presentarEstudio($estudio),
            'destino' => $canje['destino'],
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
     *
     * Dado de baja (ADR 0213): solo entra su dueño, como dueño, a descargar sus datos,
     * pagar lo pendiente o revertir la baja.
     */
    private function rolSiSuspendido(Estudio $estudio, Usuario $usuario): ?string
    {
        if ($estudio->enPlazoDeBaja()) {
            if ($usuario->tieneRol('propietario')) {
                return 'propietario';
            }

            throw ValidationException::withMessages(['email' => ['Este negocio ya no está disponible.']]);
        }
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

    /** ¿Tiene conectado Wellhub o TotalPass, o ya recibió visitantes de alguno? */
    private function conPlataformas(): bool
    {
        $integraciones = app(RegistroDeIntegracionesTenant::class);
        foreach (ProveedorPartner::valores() as $proveedor) {
            if ($integraciones->activa($proveedor)) {
                return true;
            }
        }

        return VisitantePlataformaTenant::query()->exists();
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
            // El color de su marca (su app instalada, ADR 0110).
            'color_marca' => $estudio->color_marca,
            'estado' => $estudio->estado->value,
            'estado_facturacion' => $estudio->estadoDeFacturacion()->value,
            'trial_termina_en' => $estudio->trial_termina_en?->toDateString(),
            // Dado de baja (ADR 0213): hasta cuándo están sus datos y si su dueño la revierte.
            'baja' => $estudio->enPlazoDeBaja() ? [
                'dado_de_baja_en' => $estudio->dado_de_baja_en?->toIso8601String(),
                'datos_borrar_en' => $estudio->datos_borrar_en?->toDateString(),
                'por' => $estudio->baja_por,
                'puede_revertir' => $estudio->baja_por === BajaDeNegocio::POR_DUENO,
            ] : null,
            'publicado' => $estudio->publicado,
            'en_directorio' => $estudio->enDirectorio(),
            // Perfil de negocio (R35): el frontend adapta terminologia/flags sin forks.
            'perfil' => $estudio->perfil_negocio->value,
            'perfil_config' => $estudio->perfilConfig(),
            // Solo clases o solo citas (ADR 0104): la web y la app preguntan por la
            // capacidad que manda el servidor, no la deducen del giro ni de las ofertas.
            'modalidad' => $estudio->modalidad()->value,
            // Con qué marca se presenta (ADR 0108): AgendaUno o TurnoUno.
            'producto' => $estudio->producto()->value,
            'capacidades' => $estudio->modalidad()->capacidades(),
            // ¿Trabaja con equipo (más de una persona que no es solo cliente)? Quien
            // trabaja solo no ve lo de un equipo, como la nómina; aparece al invitar.
            'con_equipo' => Usuario::query()->equipo()->count() > 1,
            // Los módulos que el superadministrador encendió (ADR 0123): la web y la app
            // solo muestran estos; el servidor niega los demás.
            'modulos' => $estudio->modulosHabilitados(),
            // Manda avisos por WhatsApp a sus clientes (ADR 0069).
            'whatsapp_clientes' => app(WhatsAppTenant::class)->enUso(),
            // Su moneda y su zona horaria (ADR 0099); con pesos mexicanos cobra en
            // línea y (en México) factura.
            'moneda' => app(ParametrosTenant::class)->moneda(),
            // Precio por forma de pago (ADR 0191): el descuento al cobrar de contado en caja.
            'descuento_contado_pct' => app(ParametrosTenant::class)->entero('ventas.descuento_contado_pct'),
            'zona_horaria' => app(FechasNegocioTenant::class)->zona(),
            // Su desfase de hoy con UTC (minutos): la app sabe qué día es en el negocio
            // antes de cargar su agenda, sin un paquete de zonas (ADR 0166).
            'zona_desfase_min' => CarbonImmutable::now(app(FechasNegocioTenant::class)->zona())->utcOffset(),
            // Su país y su lada (ADR 0103): la de los celulares que se capturan sin «+».
            'pais' => app(RegionNegocioTenant::class)->pais(),
            'lada' => app(RegionNegocioTenant::class)->lada(),
            'cobra_en_linea_posible' => app(RegionNegocioTenant::class)->enPesos(),
            'factura_posible' => app(RegionNegocioTenant::class)->factura(),
            // Wellhub y TotalPass solo operan en México.
            'bienestar_posible' => app(RegionNegocioTenant::class)->enMexico(),
            // ¿Recibe visitantes de Wellhub o TotalPass (conectado, o con visitas ya
            // registradas)? Solo entonces se ofrece su reporte (ADR 0148).
            'plataformas_bienestar' => $estudio->modalidad() === ModalidadServicio::Clases && $this->conPlataformas(),
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
