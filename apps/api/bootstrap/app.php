<?php

declare(strict_types=1);

use App\Http\Middleware\CorrelationId;
use App\Modules\Platform\Operacion\ErroresPlataforma;
use App\Modules\Tenancy\Http\Middleware\AlcanceLlaveApi;
use App\Modules\Tenancy\Http\Middleware\AutenticarLlaveApi;
use App\Modules\Tenancy\Http\Middleware\AutenticarPlataforma;
use App\Modules\Tenancy\Http\Middleware\AutenticarTenant;
use App\Modules\Tenancy\Http\Middleware\ModalidadRequerida;
use App\Modules\Tenancy\Http\Middleware\PermisoTenant;
use App\Modules\Tenancy\Http\Middleware\ResolverEstudio;
use App\Support\Http\ApiExceptionRenderer;
use App\Support\ReporteDeErrores;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Correlation id runs first so every log line for the request is tagged.
        $middleware->api(prepend: [
            CorrelationId::class,
        ]);

        $middleware->alias([
            // Control plane nuevo (identidad tenant-local por BD): resuelve el
            // estudio por slug y autentica contra su propia base.
            'estudio.resolver' => ResolverEstudio::class,
            'estudio.auth' => AutenticarTenant::class,
            'puede' => PermisoTenant::class,
            // Integracion de terceros por llave de API con scopes (R40).
            'estudio.llave' => AutenticarLlaveApi::class,
            'alcance' => AlcanceLlaveApi::class,
            // Operador de plataforma (PlatformAdmin): token global.
            'plataforma.auth' => AutenticarPlataforma::class,
            // Rutas exclusivas de clases o de citas (ADR 0104): modalidad:clases|citas.
            'modalidad' => ModalidadRequerida::class,
        ]);

        // Resolve the tenant (and its query scope) BEFORE route-model binding,
        // so every bound tenant-owned model is filtered to the active tenant.
        // La autenticación propia (negocio, sesión y llave de API) va ANTES del límite
        // de peticiones: así el límite cuenta por usuario de cada negocio y no por IP
        // (toda una red detrás de una IP compartiría el cupo). AutenticarPlataforma se
        // queda DESPUÉS a propósito: su límite es por IP y frena a quien adivina el token.
        // La modalidad (ADR 0104) se revisa con el negocio resuelto, después de la sesión
        // (sin sesión, 401) y del límite (cada intento cuenta), y antes de resolver los
        // modelos de la ruta: lo del otro modelo no revela si el recurso existe.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ResolverEstudio::class,
            AuthenticatesRequests::class,
            AutenticarTenant::class,
            AutenticarLlaveApi::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            ModalidadRequerida::class,
            AuthenticatesSessions::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Stable machine-readable JSON error contract for /api/* (see docs/API.md).
        $exceptions->render(new ApiExceptionRenderer);
        // Las reglas del negocio que la API responde con un 4xx y su código (enlace
        // vencido, cupo lleno, saldo insuficiente…) no son fallas: no van al log ni al
        // monitoreo ni alertan al superadmin. Las que se responden con 5xx (la pasarela
        // no contestó) y todo lo inesperado se siguen reportando. Lo que se atrapa en
        // segundo plano usa ReporteDeErrores::reportarAtrapado() y sí se reporta.
        $exceptions->dontReportWhen(ReporteDeErrores::esReglaDelNegocio(...));
        // Todo lo que se reporta queda en el monitoreo de errores con su traza y
        // contexto, y llega al superadmin como alerta si no lo ignoró (ADR 0080); el
        // registro normal en el log sigue igual.
        $exceptions->report(function (Throwable $e): void {
            app(ErroresPlataforma::class)->desdeExcepcion($e);
        });
    })->create();
