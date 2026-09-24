<?php

declare(strict_types=1);

use App\Http\Middleware\CorrelationId;
use App\Modules\Tenancy\Http\Middleware\AlcanceLlaveApi;
use App\Modules\Tenancy\Http\Middleware\AutenticarLlaveApi;
use App\Modules\Tenancy\Http\Middleware\AutenticarPlataforma;
use App\Modules\Tenancy\Http\Middleware\AutenticarTenant;
use App\Modules\Tenancy\Http\Middleware\PermisoTenant;
use App\Modules\Tenancy\Http\Middleware\ResolverEstudio;
use App\Support\Http\ApiExceptionRenderer;
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
        ]);

        // Resolve the tenant (and its query scope) BEFORE route-model binding,
        // so every bound tenant-owned model is filtered to the active tenant.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Stable machine-readable JSON error contract for /api/* (see docs/API.md).
        $exceptions->render(new ApiExceptionRenderer);
    })->create();
