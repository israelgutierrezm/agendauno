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

        // Confirmar con la contraseña una acción delicada (descargar mis datos, pedir
        // la baja): pocos intentos por usuario de cada negocio, para que no sirva para
        // adivinarla con una sesión robada.
        RateLimiter::for('confirmar-contrasena', function (Request $request): Limit {
            $usuario = $request->attributes->get('usuario_tenant');
            $estudio = (string) ($request->route('estudio') ?? '');

            return Limit::perMinute(5)->by($usuario instanceof Usuario
                ? 'confirmar:'.$estudio.':'.$usuario->getKey()
                : 'confirmar-ip:'.$request->ip());
        });

        // Rate limit de las rutas tenant AUTENTICADAS: por usuario tenant (o IP si no
        // se resolvio), para frenar abuso/enumeracion sin castigar a todo el estudio.
        RateLimiter::for('tenant', function (Request $request): Limit {
            $usuario = $request->attributes->get('usuario_tenant');
            $clave = $usuario instanceof Usuario ? 'u:'.$usuario->getKey() : 'ip:'.$request->ip();

            return Limit::perMinute(120)->by($clave);
        });

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
}
