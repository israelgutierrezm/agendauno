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
    | Dominio base para resolver el estudio por subdominio: `{slug}.agendauno.mx`.
    | Las rutas del tenant se montan además bajo este dominio (aparte del acceso
    | por ruta `/app/{estudio}`). Ajustable por entorno (p. ej. un dominio de
    | staging o `lvh.me` para desarrollo local con subdominios).
    */
    'dominio_base' => env('APP_TENANT_DOMAIN', 'agendauno.mx'),

    /*
    | URL base del panel web (SPA de apps/web) para armar enlaces en correos
    | (p. ej. el de activación de cuenta): {url_app}/activar/{slug}?email&token.
    */
    'url_app' => env('APP_SPA_URL', 'http://localhost:5175'),

    /*
    | App móvil (ADR 0104). `version_minima`: la versión más antigua de la app que aún
    | se acepta; viaja en /yo y una instalada más vieja pide actualizarse. Se sube
    | cuando cambia un contrato que las versiones anteriores no entienden.
    */
    'app' => [
        'version_minima' => env('APP_VERSION_MINIMA_APP', '0.0.0'),
    ],

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
    | WhatsApp (Meta Cloud API, ADR 0069): el superadministrador lo enciende y carga el
    | número y el token desde su panel (se guardan cifrados en la BD). Aquí solo la
    | versión de la Graph API y la lada de último respaldo: un celular sin lada se
    | completa con la del país del negocio (ADR 0103); esta, solo si no hay otra.
    */
    'whatsapp' => [
        'version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),
        'lada' => env('WHATSAPP_LADA', '52'),
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
    | Tipo de cambio FIX del Banco de México (serie SF43718, API SIE) para cobrar en
    | pesos a los negocios de México la renta publicada en dólares (ADR 0107). El token
    | se pide gratis en banxico.org.mx; sin él, rige el que capture el superadmin.
    */
    'banxico' => [
        'token' => env('BANXICO_TOKEN'),
        'url' => env('BANXICO_URL', 'https://www.banxico.org.mx/SieAPIRest/service/v1'),
        'serie' => env('BANXICO_SERIE_FIX', 'SF43718'),
    ],

    /*
    | Ventas: a dónde se manda a quien pasa de 20 profesionales o de 1,000 alumnos
    | (cotización, ADR 0107).
    */
    'ventas' => [
        'correo' => env('VENTAS_CORREO', 'ventas@agendauno.mx'),
        'whatsapp' => env('VENTAS_WHATSAPP'),
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
    | Alertas de la plataforma: a quién se avisa (por correo, agrupado) cuando fallan
    | pagos, correos, respaldos o la cola. Sin correo no se avisa a nadie (y la
    | verificación de producción lo marca como pendiente).
    */
    'alertas' => [
        'correo' => env('ALERTAS_CORREO'),
    ],

    /*
    | Monitoreo de errores (ADR 0080, 0082). `mapas_web`: la carpeta con los mapas de
    | origen de la web compilada (la imagen web los deja ahí; no se publican), para
    | traducir sus errores al archivo y la línea originales.
    */
    'errores' => [
        'mapas_web' => env('MAPAS_WEB_DIR', storage_path('app/mapas-web')),
    ],

    /*
    | Respaldos de la base de cada negocio y de la plataforma (diarios). En
    | producción conviene un disco S3 (otro lugar que el servidor). Se conservan
    | `dias` días. En MySQL usa los binarios mysqldump/mysql del servidor.
    |
    | `tls`: cómo se conectan esos binarios. La imagen trae el cliente de MariaDB,
    | que desde la 11.4 cifra y verifica el certificado del servidor por omisión.
    | `ca`: la CA con que se verifica (vacía: la de la conexión de PHP,
    | MYSQL_ATTR_SSL_CA). `verificar` en false acepta el certificado autofirmado de
    | un MySQL 8 en la red privada (la conexión sigue cifrada). `opciones`: otras
    | opciones para mysqldump y mysql, separadas por espacios (p. ej.
    | `--ssl-mode=REQUIRED` con el cliente de Oracle MySQL).
    */
    'respaldos' => [
        'disco' => env('RESPALDOS_DISCO', 'local'),
        'carpeta' => env('RESPALDOS_CARPETA', 'respaldos'),
        'dias' => (int) env('RESPALDOS_DIAS', 14),
        'mysqldump' => env('RESPALDOS_MYSQLDUMP', 'mysqldump'),
        'mysql' => env('RESPALDOS_MYSQL', 'mysql'),
        'tls' => [
            'ca' => env('RESPALDOS_MYSQL_SSL_CA'),
            'verificar' => (bool) env('RESPALDOS_MYSQL_SSL_VERIFICAR', true),
        ],
        'opciones' => env('RESPALDOS_MYSQL_OPCIONES', ''),
    ],

    /*
    | Operación. `apertura_comercial`: la instalación ya cobra la renta del SaaS con
    | dinero real. La verificación de producción exige entonces Stripe en modo live;
    | sin ella, es una instalación de prueba y así lo dice.
    */
    /*
    | Plataformas de bienestar (solo negocios en México): Wellhub (Access Control API)
    | y TotalPass (uso del token). En desarrollo, sus ambientes de prueba:
    | WELLHUB_API_URL=https://apitesting.partners.gympass.com
    */
    'integraciones' => [
        'wellhub_url' => env('WELLHUB_API_URL', 'https://api.partners.gympass.com'),
        'totalpass_url' => env('TOTALPASS_API_URL', 'https://api.totalpass.com'),
    ],

    'operacion' => [
        'apertura_comercial' => (bool) env('APERTURA_COMERCIAL', false),
    ],

];
