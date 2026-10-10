<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Platform\Operacion\AlertasPlataforma;
use App\Modules\Platform\Operacion\LatidoOperacion;
use App\Modules\Tenancy\Events\EventoDeDominioTenant;
use App\Modules\Tenancy\Facturacion\ClienteFacturacion;
use App\Modules\Tenancy\Facturacion\FacturacionFalsa;
use App\Modules\Tenancy\Facturacion\FacturacionNoConfigurada;
use App\Modules\Tenancy\Facturacion\FacturApiHttp;
use App\Modules\Tenancy\Listeners\AcumularPuntos;
use App\Modules\Tenancy\Listeners\DevolverPagoAlCancelarNegocio;
use App\Modules\Tenancy\Listeners\EjecutarAutomatizaciones;
use App\Modules\Tenancy\Listeners\EnviarWebhooksSalientes;
use App\Modules\Tenancy\Listeners\GenerarComunicaciones;
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
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Una sola instancia: recuerda qué excepciones ya registró con su tipo.
        $this->app->singleton(AlertasPlataforma::class);
        // Proveedor de facturación (CFDI): FacturAPI real si hay llave maestra de
        // plataforma; si no, el falso en desarrollo y pruebas, y en producción uno que
        // no timbra (nunca un CFDI simulado a un cliente real).
        $this->app->bind(ClienteFacturacion::class, function (): ClienteFacturacion {
            // La llave maestra la resuelve la plataforma (config en BD, con respaldo a env).
            $llave = ConfiguracionPlataforma::llaveFacturapi();
            if ($llave !== null) {
                return new FacturApiHttp((string) config('agendauno.facturapi.base_url'));
            }

            return $this->app->environment('production') ? new FacturacionNoConfigurada : new FacturacionFalsa;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rate limit de autenticación (SEC-03): combina identidad+IP e IP sola para
        // frenar fuerza bruta y credential stuffing sin castigar a un tenant entero.
        RateLimiter::for('login', function (Request $request): array {
            $email = Str::lower((string) $request->input('email'));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by($email.'|'.$ip),
                Limit::perMinute(20)->by($ip),
            ];
        });

        // Recuperación de contraseña: aparte del login (no gasta sus intentos) y más
        // estricta por correo, para que no sirva para inundar un buzón.
        RateLimiter::for('recuperacion', function (Request $request): array {
            $email = Str::lower((string) $request->input('email'));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(3)->by($email.'|'.$ip),
                Limit::perMinute(10)->by($ip),
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

        // Calendario personal (iCal): lo consultan Google, Apple u Outlook desde pocas IP
        // para los clientes de todos los negocios, así que se cuenta por enlace.
        RateLimiter::for('calendario', fn (Request $request): Limit => Limit::perMinute(60)
            ->by(self::negocioDe($request).'|'.self::textoDeRuta($request, 'token')));

        // Páginas públicas de AgendaUno sin negocio (registro, directorio, legales).
        RateLimiter::for('publico', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip()));

        // Superadmin: va antes de validar su token (para frenar a quien lo adivina).
        RateLimiter::for('plataforma', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip()));

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
        // Si el negocio cancela algo ya pagado en línea, lo devuelve (si así lo decide; ADR 0046).
        Event::listen(EventoDeDominioTenant::class, DevolverPagoAlCancelarNegocio::class);
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
     * Nombre de la ruta sin el prefijo del montaje (`api.v1.app.`, `api.v1.sub.` o
     * `api.v1.sub-{producto}.`): la misma pantalla cuenta igual entre por ruta o por el
     * subdominio de cualquier producto.
     */
    private static function rutaTenant(Request $request): string
    {
        return (string) preg_replace('/^api\.v1\.(app|sub|sub-[a-z]+)\./', '', (string) $request->route()?->getName());
    }

    private static function textoDeRuta(Request $request, string $parametro): string
    {
        $valor = $request->route($parametro);

        return is_string($valor) ? $valor : '';
    }
}
