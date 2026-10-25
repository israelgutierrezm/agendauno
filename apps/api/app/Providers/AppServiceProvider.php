<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Platform\Operacion\LatidoOperacion;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Facturacion\CancelacionCfdi;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\FacturacionFalsa;
use App\Modules\Tenancy\Facturacion\FacturacionNoConfigurada;
use App\Modules\Tenancy\Facturacion\FacturApiHttp;
use App\Modules\Tenancy\Listeners\AcumularPuntos;
use App\Modules\Tenancy\Listeners\AvisarCambiosAPlataformas;
use App\Modules\Tenancy\Listeners\DevolverPagoAlCancelarNegocio;
use App\Modules\Tenancy\Listeners\DevolverPagoDeCitaConAnticipo;
use App\Modules\Tenancy\Listeners\EjecutarAutomatizaciones;
use App\Modules\Tenancy\Listeners\EnviarWebhooksSalientes;
use App\Modules\Tenancy\Listeners\FacturarCitaAlPagarse;
use App\Modules\Tenancy\Listeners\GenerarComunicaciones;
use App\Modules\Tenancy\Listeners\RegistrarConversionesMedicion;
use App\Modules\Tenancy\Listeners\ReservarInscritosEnFechasNuevas;
use App\Modules\Tenancy\Models\ConfiguracionPlataforma;
use App\Modules\Tenancy\Models\LlaveApiTenant;
use App\Modules\Tenancy\Models\Usuario;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /** Intentos de entrar a una cuenta (negocio y correo) en una hora, desde cualquier IP. */
    public const INTENTOS_POR_CUENTA_HORA = 30;

    /** Enlaces para restablecer la contraseña de una cuenta en una hora, desde cualquier IP. */
    public const RECUPERACIONES_POR_CUENTA_HORA = 10;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Una sola instancia: recuerda qué excepciones ya registró con su tipo.
        $this->app->singleton(AlertasPlataforma::class);
        // Proveedor de facturación (CFDI): el real solo con llave de FacturAPI.
        $this->app->bind(ClienteFacturacion::class, fn (): ClienteFacturacion => $this->proveedorDeFacturacion());
        // Cancelar un CFDI (ADR 0180): el mismo proveedor.
        $this->app->bind(CancelacionCfdi::class, fn (): CancelacionCfdi => $this->proveedorDeFacturacion());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rate limit de autenticación (SEC-03): combina identidad+IP e IP sola para
        // frenar fuerza bruta y credential stuffing sin castigar a un tenant entero.
        // Además, un tope por cuenta (el negocio y el correo) en la hora, venga de la IP que
        // venga: una red de muchas IP no prueba contraseñas sin fin contra un correo.
        RateLimiter::for('login', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email')));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by($email.'|'.$ip),
                Limit::perMinute(20)->by($ip),
                ...self::porCuenta($request, $email, self::INTENTOS_POR_CUENTA_HORA),
            ];
        });

        // Recuperación de contraseña: aparte del login (no gasta sus intentos) y más
        // estricta por correo, para que no sirva para inundar un buzón (ni desde muchas IP).
        RateLimiter::for('recuperacion', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email')));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(3)->by($email.'|'.$ip),
                Limit::perMinute(10)->by($ip),
                ...self::porCuenta($request, $email, self::RECUPERACIONES_POR_CUENTA_HORA),
            ];
        });

        // Los límites de las rutas con sesión corren DESPUÉS de resolver el negocio y
        // autenticar (prioridad en bootstrap/app.php): ya saben quién pide. Cada familia
        // lleva su propio contador, así que agotar una no bloquea a las demás.

        // Confirmar con la contraseña una acción delicada (descargar mis datos, pedir
        // la baja): pocos intentos por usuario de cada negocio, para que no sirva para
        // adivinarla con una sesión robada.
        RateLimiter::for('confirmar-contrasena', fn (Request $request): Limit => Limit::perMinute(5)
            ->by(self::quienEnElNegocio($request)));

        // Rutas tenant AUTENTICADAS (sesión o llave de API): por usuario o llave de cada
        // negocio, para frenar abuso/enumeración sin castigar a todo el estudio ni a
        // quienes comparten la red (wifi del estudio, datos móviles).
        // Los avisos de Wellhub y TotalPass llegan de sus servidores (las mismas IP para
        // todos los negocios): el límite es de cada negocio, no compartido (ADR 0202), y
        // de cada token de su dirección, que se revisa después: quien manda avisos con un
        // token falso gasta su propio cupo, no el de los avisos de verdad.
        RateLimiter::for('bienestar-webhook', fn (Request $request): Limit => Limit::perMinute(max(1, (int) config('agendauno.integraciones.avisos_bienestar_por_minuto', 600)))
            ->by('bienestar:'.self::negocioDe($request).':'.hash('sha256', self::textoDeRuta($request, 'token'))));

        RateLimiter::for('tenant', fn (Request $request): Limit => Limit::perMinute(120)
            ->by(self::quienEnElNegocio($request)));

        // Clima (Inicio del equipo y del cliente): consulta a un servicio externo, con
        // su propio tope por usuario.
        RateLimiter::for('clima', fn (Request $request): Limit => Limit::perMinute(30)
            ->by(self::quienEnElNegocio($request)));

        // Verificar el WhatsApp desde el panel: pocos códigos (cuestan y llegan a un
        // teléfono) y pocos intentos, por usuario y por pantalla.
        RateLimiter::for('whatsapp-panel-codigo', fn (Request $request): Limit => Limit::perMinutes(10, 5)
            ->by(self::quienEnElNegocio($request).'|'.self::rutaTenant($request)));
        RateLimiter::for('whatsapp-panel-verificar', fn (Request $request): Limit => Limit::perMinutes(10, 20)
            ->by(self::quienEnElNegocio($request).'|'.self::rutaTenant($request)));

        // Página pública del negocio y reserva sin cuenta (marca, escaparate, opciones,
        // días y horarios): por negocio e IP, con margen para buscar horario varias veces.
        RateLimiter::for('negocio-publico', fn (Request $request): Limit => Limit::perMinute(120)
            ->by(self::negocioDe($request).'|'.$request->ip()));

        // Formularios de captación (ADR 0117): pocos envíos por negocio e IP; el captcha
        // filtra el resto.
        RateLimiter::for('captacion', fn (Request $request): array => [
            Limit::perMinute(5)->by('captacion|'.self::negocioDe($request).'|'.$request->ip()),
            Limit::perHour(30)->by('captacion-hora|'.self::negocioDe($request).'|'.$request->ip()),
        ]);

        // Calendario personal (iCal): lo consultan Google, Apple u Outlook desde pocas IP
        // para los clientes de todos los negocios, así que se cuenta por enlace.
        RateLimiter::for('calendario', fn (Request $request): Limit => Limit::perMinute(60)
            ->by(self::negocioDe($request).'|'.self::textoDeRuta($request, 'token')));

        // Agendar una demo desde la landing (ADR 0204): pocas por IP (en 10 minutos y en
        // el día) y pocas por correo y por teléfono en el día, para que nadie llene la
        // agenda de ventas con envíos en masa. Cuentan también los intentos rechazados:
        // hay margen para corregir un dato. El captcha y el sello de tiempo filtran el
        // resto.
        RateLimiter::for('demos', function (Request $request): array {
            $ip = (string) $request->ip();
            $correo = Str::lower(trim((string) $request->input('correo')));
            $telefono = (string) preg_replace('/\D/', '', (string) $request->input('telefono'));

            return [
                Limit::perMinutes(10, 5)->by('demos|'.$ip),
                Limit::perDay(15)->by('demos-dia|'.$ip),
                Limit::perDay(5)->by('demos-correo|'.hash('sha256', $correo)),
                Limit::perDay(5)->by('demos-telefono|'.hash('sha256', $telefono)),
            ];
        });

        // Páginas públicas de AgendaUno sin negocio (registro, directorio, legales).
        RateLimiter::for('publico', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip()));
        // El servicio web de Apple Wallet: muchos teléfonos detrás de una IP de Apple no,
        // pero sí varias peticiones seguidas de un teléfono al registrar sus pases.
        RateLimiter::for('wallet', fn (Request $request): Limit => Limit::perMinute(120)->by((string) $request->ip()));

        // Superadmin: va antes de validar su token (para frenar a quien lo adivina).
        RateLimiter::for('plataforma', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip()));
        // Entrar al superadmin (ADR 0126): pocos intentos por IP y por correo.
        RateLimiter::for('plataforma-acceso', fn (Request $request): array => [
            Limit::perMinute(20)->by('ip|'.$request->ip()),
            Limit::perMinute(10)->by('correo|'.mb_strtolower((string) $request->input('email', $request->input('desafio', '')))),
        ]);

        // Consumidores del outbox: los eventos de dominio publicados por el relay se
        // entregan a los webhooks salientes del estudio (R40) y generan las
        // comunicaciones (R28) definidas por plantilla.
        // Un trabajo de la cola que agotó sus intentos: alerta al superadmin.
        Event::listen(JobFailed::class, function (JobFailed $evento): void {
            app(AlertasPlataforma::class)->registrarExcepcion(
                'trabajo_fallido',
                $evento->job->resolveName(),
                $evento->exception,
            );
        });
        // El worker avisa que arrancó, con su versión y su conexión de cola al alcance.
        // En mantenimiento no toma trabajos (ni el del latido): con esta marca la
        // publicación sabe que la cola nueva está en marcha sin abrirla.
        Event::listen(WorkerStarting::class, function (WorkerStarting $evento): void {
            try {
                Queue::connection($evento->connectionName)->size(explode(',', $evento->queue)[0]);
                app(LatidoOperacion::class)->marcarArranque(LatidoOperacion::COLA);
            } catch (Throwable $e) {
                report($e);
            }
        });
        Event::listen(EventoDeDominioTenant::class, EnviarWebhooksSalientes::class);
        Event::listen(EventoDeDominioTenant::class, GenerarComunicaciones::class);
        Event::listen(EventoDeDominioTenant::class, EjecutarAutomatizaciones::class);
        // Lealtad (R24): acumula puntos al asistir (asistencia.marcada) o comprar (orden.pagada).
        Event::listen(EventoDeDominioTenant::class, AcumularPuntos::class);
        // Las fechas que se generan después también son de los inscritos de un grupo.
        Event::listen(EventoDeDominioTenant::class, ReservarInscritosEnFechasNuevas::class);
        // Si el negocio cancela algo ya pagado en línea, lo devuelve (si así lo decide; ADR 0046).
        Event::listen(EventoDeDominioTenant::class, DevolverPagoAlCancelarNegocio::class);
        // Cita con anticipo (ADR 0161, 0181): si el cliente cancela a tiempo se le devuelve
        // todo; si cancela tarde o no llega, lo que pagó de más sobre el anticipo.
        Event::listen(EventoDeDominioTenant::class, DevolverPagoDeCitaConAnticipo::class);
        // La factura automática de una cita al pagarse, si su cliente la pidió.
        Event::listen(EventoDeDominioTenant::class, FacturarCitaAlPagarse::class);
        // GA4 y Meta del negocio (ADR 0143): anota sus conversiones; se mandan aparte.
        Event::listen(EventoDeDominioTenant::class, RegistrarConversionesMedicion::class);
        // Wellhub y TotalPass ven al momento los lugares de sus clases (ADR 0202).
        Event::listen(EventoDeDominioTenant::class, AvisarCambiosAPlataformas::class);
    }

    /**
     * Quién pide dentro de su negocio: el usuario con sesión, la llave de API o, si
     * aún no se autenticó, la IP. Siempre con el negocio: los ids son de la base de
     * cada uno (el usuario 1 de un negocio no es el usuario 1 de otro).
     */
    private static function quienEnElNegocio(Request $request): string
    {
        $negocio = self::negocioDe($request);
        $usuario = $request->attributes->get('usuario_tenant');
        if ($usuario instanceof Usuario) {
            return $negocio.'|u:'.$usuario->getKey();
        }
        $llave = $request->attributes->get('llave_api');
        if ($llave instanceof LlaveApiTenant) {
            return $negocio.'|llave:'.$llave->getKey();
        }

        return $negocio.'|ip:'.$request->ip();
    }

    /**
     * Slug del negocio de la ruta (por ruta o por subdominio); vacío fuera de un negocio.
     */
    private static function negocioDe(Request $request): string
    {
        return Str::lower(self::textoDeRuta($request, 'estudio'));
    }

    /**
     * El tope por cuenta (negocio y correo) en la hora; sin correo en la petición no hay
     * cuenta que cuidar (no se juntan en un mismo cupo todas las que no lo traen).
     *
     * @return list<Limit>
     */
    private static function porCuenta(Request $request, string $email, int $porHora): array
    {
        return $email === '' ? [] : [Limit::perHour($porHora)->by('cuenta|'.self::negocioDe($request).'|'.$email)];
    }

    /**
     * Nombre de la ruta sin el prefijo del montaje (`api.v1.app.`, `api.v1.sub.` o
     * `api.v1.sub-{producto}.`): la misma pantalla cuenta igual entre por ruta o por el
     * subdominio de cualquier producto.
     */
    private static function rutaTenant(Request $request): string
    {
        return (string) preg_replace('/^api\.v1\.(app|sub|sub-[a-z]+)\./', '', (string) $request->route()?->getName());
    }

    /**
     * Proveedor de facturación (CFDI): FacturAPI real si hay llave maestra de
     * plataforma; si no, el falso en desarrollo y pruebas, y en producción uno que no
     * timbra (nunca un CFDI simulado a un cliente real).
     */
    private function proveedorDeFacturacion(): CancelacionCfdi&ClienteFacturacion
    {
        // La llave maestra la resuelve la plataforma (config en BD, con respaldo a env).
        if (ConfiguracionPlataforma::llaveFacturapi() !== null) {
            return new FacturApiHttp((string) config('agendauno.facturapi.base_url'));
        }

        return $this->app->environment('production') ? new FacturacionNoConfigurada : new FacturacionFalsa;
    }

    private static function textoDeRuta(Request $request, string $parametro): string
    {
        $valor = $request->route($parametro);

        return is_string($valor) ? $valor : '';
    }
}
