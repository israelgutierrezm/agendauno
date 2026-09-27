<?php

declare(strict_types=1);

return [

    /*
    | Paths that accept cross-origin requests. Los fronts se autentican por bearer.
    */
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Flujo multi-tenant (registro/directorio/login por estudio). Autenticacion por
    // bearer token, no por cookie, por lo que no necesita dominio stateful. Admite
    // varias URLs separadas por coma.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_REGISTRO_URL', 'http://localhost:5175')),
    ))),

    'allowed_origins_patterns' => array_values(array_filter([
        // La app de cada negocio en su subdominio ({slug}.dominio) y el dominio base.
        '#^https://([a-z0-9-]+\.)?'.preg_quote((string) env('APP_TENANT_DOMAIN', 'agendauno.mx'), '#').'$#',
        // En desarrollo: localhost, 127.0.0.1 y {slug}.localhost en cualquier puerto.
        env('APP_ENV') === 'local' ? '#^http://(localhost|127\.0\.0\.1|[a-z0-9-]+\.localhost)(:\d+)?$#' : null,
    ])),

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Correlation-ID'],

    'max_age' => 0,

    // Required for Sanctum cookie-based SPA authentication.
    'supports_credentials' => true,

];
