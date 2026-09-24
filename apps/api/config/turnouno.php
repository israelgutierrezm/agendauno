<?php

declare(strict_types=1);

return [

    /*
    | Driver de las bases de datos por tenant (data plane). En dev/test cada tenant
    | es un archivo SQLite (aislamiento físico simple); en producción se apunta a
    | MySQL (una base por tenant) con TENANT_DB_DRIVER=mysql.
    */
    'tenant_db_driver' => env('TENANT_DB_DRIVER', 'sqlite'),

    /*
    | Días del periodo de prueba gratuito al aprovisionar un estudio.
    */
    'dias_trial' => (int) env('TRIAL_DIAS', 14),

    /*
    | Dominio base para resolver el estudio por subdominio: `{slug}.agendauno.mx`.
    | Las rutas del tenant se montan además bajo este dominio (aparte del acceso
    | por ruta `/app/{estudio}`). Ajustable por entorno (p. ej. un dominio de
    | staging o `lvh.me` para desarrollo local con subdominios).
    */
    'dominio_base' => env('APP_TENANT_DOMAIN', 'agendauno.mx'),

    /*
    | URL base del panel web (SPA registro-web) para armar enlaces en correos
    | (p. ej. el de activación de cuenta): {url_app}/activar/{slug}?email&token.
    */
    'url_app' => env('APP_SPA_URL', 'http://localhost:5175'),

    /*
    | Facturación electrónica (CFDI) vía FacturAPI. La plataforma usa UNA cuenta
    | FacturAPI (multi-organización): su llave MAESTRA vive aquí (env, gestionada
    | por ops), y cada tenant carga sus propios datos fiscales que se materializan
    | como una "Organization" bajo esa cuenta. La llave por tenant (de su
    | organización) se guarda cifrada en su propia BD. Sin `llave` la facturación
    | opera en modo no-configurado (no timbra).
    */
    'facturapi' => [
        'llave' => env('FACTURAPI_LLAVE'),
        'base_url' => env('FACTURAPI_URL', 'https://www.facturapi.io/v2'),

        /*
        | CFDI de la RENTA del SaaS (plataforma -> dueño). Claves SAT por defecto para
        | el concepto "suscripción AgendaUno"; ajustables por entorno sin tocar código.
        */
        'renta' => [
            'clave_prod_serv' => env('FACTURAPI_RENTA_CLAVE_PROD_SERV', '81112100'), // Servicios de sistemas de información
            'clave_unidad' => env('FACTURAPI_RENTA_CLAVE_UNIDAD', 'E48'), // Unidad de servicio
            'uso_cfdi' => env('FACTURAPI_RENTA_USO_CFDI', 'G03'), // Gastos en general
            'forma_pago' => env('FACTURAPI_RENTA_FORMA_PAGO', '04'), // Tarjeta de crédito
        ],
    ],

    /*
    | Administración de plataforma (PlatformAdmin): el operador de AgendaUno ve
    | todos los estudios y carga credenciales globales (p. ej. la cuenta
    | FacturAPI). Se autentica con un token dedicado (env). Sin token, el apartado
    | queda deshabilitado (todas sus rutas responden 401).
    */
    'plataforma' => [
        'token' => env('PLATFORM_ADMIN_TOKEN'),
    ],

    /*
    | reCAPTCHA v3 (Google) para el registro público de negocios. Si no hay
    | `secret` configurado, la verificación se omite (dev/local). Con secret, el
    | token del cliente se valida contra Google y se rechaza bajo el umbral.
    */
    'recaptcha' => [
        'secret' => env('RECAPTCHA_SECRET'),
        'min_score' => (float) env('RECAPTCHA_MIN_SCORE', 0.5),
    ],

    /*
    | Respaldos de la base de cada negocio (turnouno:respaldar-estudios, diario).
    | En producción conviene un disco S3 (otro lugar que el servidor). Se conservan
    | `dias` días. En MySQL usa los binarios mysqldump/mysql del servidor.
    */
    'respaldos' => [
        'disco' => env('RESPALDOS_DISCO', 'local'),
        'carpeta' => env('RESPALDOS_CARPETA', 'respaldos'),
        'dias' => (int) env('RESPALDOS_DIAS', 14),
        'mysqldump' => env('RESPALDOS_MYSQLDUMP', 'mysqldump'),
        'mysql' => env('RESPALDOS_MYSQL', 'mysql'),
    ],

];
