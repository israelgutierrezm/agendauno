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
    | Productos comerciales (ADR 0108): una API, dos marcas. El de un negocio sale de
    | su modalidad (`ProductoComercial`): clases → AgendaUno, citas → TurnoUno. Cada uno
    | tiene su dominio (sus negocios viven en `{slug}.{dominio}`) y la URL de su web
    | (base de los enlaces de correos y avisos). AgendaUno usa `dominio_base` y
    | `url_app` (APP_TENANT_DOMAIN, APP_SPA_URL), como siempre. En desarrollo, sin
    | TURNOUNO_URL_WEB, los dos usan la misma web local.
    */
    'productos' => [
        'agendauno' => [
            'nombre' => env('AGENDAUNO_NOMBRE', 'AgendaUno'),
            // Sin valor, sale de MAIL_FROM_ADDRESS.
            'correo_remitente' => env('AGENDAUNO_MAIL_FROM'),
        ],
        'turnouno' => [
            'nombre' => env('TURNOUNO_NOMBRE', 'TurnoUno'),
            'dominio' => env('TURNOUNO_DOMINIO', 'turnouno.mx'),
            'url_web' => env('TURNOUNO_URL_WEB', env('APP_SPA_URL', 'http://localhost:5175')),
            'correo_remitente' => env('TURNOUNO_MAIL_FROM'),
            // Cotizaciones de TurnoUno. Sin valor, el general (`ventas.correo`).
            'ventas_correo' => env('TURNOUNO_VENTAS_CORREO'),
        ],
    ],

    /*
    | Dominio propio de cada negocio (ADR 0189). El negocio apunta su dominio con un
    | CNAME a `sitios.{dominio de su producto}` (o `destino`, si se fija) o, en la raíz
    | de su dominio, con registros A a `ips`; y prueba que es suyo con un TXT en
    | `_agendauno.{su dominio}`. `archivo_traefik`: donde se escribe la configuración
    | dinámica de Traefik con los dominios activos (vacío: no se escribe); sus
    | certificados los pide `certresolver` por HTTP-01 (el de DNS-01 de Cloudflare no
    | sirve para dominios ajenos).
    */
    'dominios_propios' => [
        'destino' => env('DOMINIOS_PROPIOS_DESTINO', ''),
        'ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOMINIOS_PROPIOS_IPS', ''))))),
        'archivo_traefik' => env('DOMINIOS_PROPIOS_TRAEFIK', ''),
        'certresolver' => env('DOMINIOS_PROPIOS_CERTRESOLVER', 'letsencrypt'),
        'entrada_traefik' => env('TRAEFIK_ENTRADA', 'websecure'),
        'servicio_traefik' => env('DOMINIOS_PROPIOS_SERVICIO', 'agendauno-web@docker'),
    ],

    /*
    | Pase de entrada en Apple Wallet y Google Wallet (ADR 0195). Las credenciales son de
    | la plataforma; sin ellas la cartera no se ofrece.
    | - Apple: el identificador del tipo de pase y del equipo, el certificado del tipo de
    |   pase (.p12 con su clave, o PEM con su llave) y el intermedio WWDR (G4) de Apple.
    | - Google: el id del emisor y el archivo JSON de la cuenta de servicio.
    */
    'wallet' => [
        'apple' => [
            'pass_type_id' => env('WALLET_APPLE_PASS_TYPE_ID', ''),
            'team_id' => env('WALLET_APPLE_TEAM_ID', ''),
            'certificado' => env('WALLET_APPLE_CERTIFICADO', ''),
            'clave' => env('WALLET_APPLE_CLAVE', ''),
            'wwdr' => env('WALLET_APPLE_WWDR', ''),
        ],
        'google' => [
            'issuer_id' => env('WALLET_GOOGLE_ISSUER_ID', ''),
            'cuenta_servicio' => env('WALLET_GOOGLE_CUENTA_SERVICIO', ''),
        ],
    ],

    /*
    | Reserva con Google (ADR 0198), integración «redirect» del Actions Center. Sin el id
    | de socio que da Google al aprobar a la plataforma no se genera ni se avisa nada.
    | Los feeds se escriben en `disco`/`carpeta`; la plataforma los sube al SFTP de Google.
    */
    'reserva_google' => [
        'partner_id' => env('RESERVA_GOOGLE_PARTNER_ID', ''),
        'disco' => env('RESERVA_GOOGLE_DISCO', 'local'),
        'carpeta' => env('RESERVA_GOOGLE_CARPETA', 'reserva-google'),
        'url_conversion' => env('RESERVA_GOOGLE_URL_CONVERSION', 'https://www.google.com/maps/conversion/collect'),
    ],

    /*
    | Google Calendar y Outlook en las dos direcciones (ADR 0199): los clientes OAuth son de
    | la plataforma (uno de Google Cloud y uno de Microsoft Entra), con la vuelta
    | `{APP_URL}/api/v1/calendarios/{google|microsoft}/vuelta`. Sin ellos no se ofrece.
    | `dias`: cuántos días hacia adelante se publican y se leen.
    */
    'calendarios' => [
        'dias' => (int) env('CALENDARIOS_DIAS', 60),
        'google' => [
            'client_id' => env('CALENDARIOS_GOOGLE_CLIENT_ID', ''),
            'client_secret' => env('CALENDARIOS_GOOGLE_CLIENT_SECRET', ''),
        ],
        'microsoft' => [
            'client_id' => env('CALENDARIOS_MICROSOFT_CLIENT_ID', ''),
            'client_secret' => env('CALENDARIOS_MICROSOFT_CLIENT_SECRET', ''),
            'tenant' => env('CALENDARIOS_MICROSOFT_TENANT', 'common'),
        ],
    ],

    /*
    | El asistente sobre los datos del negocio (ADR 0196): Claude por la API de Anthropic.
    | La llave es de la plataforma; sin ella no se ofrece. Lo enciende el superadmin
    | (parámetro `asistente.activo`).
    */
    'asistente' => [
        'llave' => env('ASISTENTE_ANTHROPIC_KEY', ''),
        'modelo' => env('ASISTENTE_MODELO', 'claude-sonnet-5-5'),
        'url' => env('ASISTENTE_URL', 'https://api.anthropic.com/v1/messages'),
        'timeout' => (int) env('ASISTENTE_TIMEOUT', 60),
    ],

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
    | FacturAPI). Entra cada operador con su correo, su contraseña, el token dedicado
    | (env) y un código por correo (ADR 0126). Sin token, el apartado queda
    | deshabilitado (todas sus rutas responden 401).
    */
    'plataforma' => [
        'token' => env('PLATFORM_ADMIN_TOKEN'),
        // ¿El token solo abre la API (scripts, pruebas)? En producción, no.
        'token_directo' => (bool) env('PLATFORM_ADMIN_TOKEN_DIRECTO', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
        // El código por correo: minutos que vale e intentos antes de pedir otro.
        'minutos_codigo' => (int) env('PLATFORM_CODIGO_MINUTOS', 10),
        'intentos_codigo' => (int) env('PLATFORM_CODIGO_INTENTOS', 5),
        // Cuánto dura una sesión del superadmin.
        'horas_sesion' => (int) env('PLATFORM_SESION_HORAS', 12),
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
        // La zona de los horarios de las demos (ADR 0193).
        'zona' => env('VENTAS_ZONA', 'America/Mexico_City'),
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
    | Archivos que suben los negocios (ADR 0170). `publicos`: lo que se ve en páginas
    | públicas (logos, fotos, imágenes del sitio); `privados`: los documentos, que
    | solo se descargan por la API. Por omisión, el volumen del servidor (`public` y
    | `local`); en producción pueden ir a S3 (`s3_publicos` y `s3_privados`). Lo que ya
    | estaba se mueve con `agendauno:mover-archivos`.
    */
    'archivos' => [
        'publicos' => env('ARCHIVOS_DISCO_PUBLICO', 'public'),
        'privados' => env('ARCHIVOS_DISCO_PRIVADO', 'local'),
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
        // Cifrado (ADR 0171): 32 bytes en base64 (openssl rand -base64 32). Sin ella
        // se guardan sin cifrar. Al cambiarla, la de antes va en las anteriores
        // (separadas por comas) para restaurar lo viejo. Guárdala FUERA del servidor:
        // sin ella no se puede restaurar.
        'llave' => env('RESPALDOS_LLAVE'),
        'llaves_anteriores' => env('RESPALDOS_LLAVES_ANTERIORES', ''),
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
        // Check-in automático (ADR 0148). Wellhub firma sus avisos con la llave secreta
        // que entrega a AgendaUno como sistema integrado (un negocio puede tener la suya).
        'wellhub_webhook_secret' => env('WELLHUB_WEBHOOK_SECRET'),
        // API nueva de TotalPass: la llave de AgendaUno como integrador más la de cada
        // sede (`place_api_key`) dan una sesión de 24 h. La dirección de confirmación del
        // check-in de producción falta confirmarla con TotalPass (documentan la de pruebas).
        'totalpass_partner_api_key' => env('TOTALPASS_PARTNER_API_KEY'),
        'totalpass_gym_url' => env('TOTALPASS_GYM_API_URL', 'https://gym-service-api.totalpass.com'),
        'totalpass_admin_url' => env('TOTALPASS_ADMIN_URL', 'https://admin.totalpass.com'),
        // Reservas desde la app (ADR 0149). La Booking API de Wellhub usa la misma dirección
        // y el mismo token que su Access Control API; sus avisos de reserva se registran
        // por gimnasio con su Integration Setup API.
        'wellhub_setup_url' => env('WELLHUB_SETUP_API_URL', 'https://api.partners-integrations.gympass.com'),
        // La Booking API de TotalPass: misma llave de integrador y la de cada sede.
        'totalpass_booking_url' => env('TOTALPASS_BOOKING_API_URL', 'https://booking-api.totalpass.com'),
    ],

    'operacion' => [
        'apertura_comercial' => (bool) env('APERTURA_COMERCIAL', false),
    ],

    /*
    | Medición con Google Analytics 4 y Meta (ADR 0143). Los IDs y secretos de cada
    | destino se configuran en el superadmin (los del SaaS, por producto) y en
    | Integraciones de cada negocio (los suyos), no aquí.
    | - `meta_version`: versión de la Graph API para la Conversions API.
    | - `enviar_fuera_de_produccion`: fuera de producción nada sale a un destino real
    |   (se registra como omitido); encenderlo solo en un entorno de pruebas con
    |   destinos de prueba.
    | - `retencion_identificadores_dias`: después, se borran del contexto los
    |   identificadores del navegador (client_id, fbp, fbc, agente e IP).
    */
    'medicion' => [
        'meta_version' => env('MEDICION_META_VERSION', 'v26.0'),
        'ga4_url' => env('MEDICION_GA4_URL', 'https://www.google-analytics.com'),
        'meta_url' => env('MEDICION_META_URL', 'https://graph.facebook.com'),
        'enviar_fuera_de_produccion' => (bool) env('MEDICION_ENVIAR_FUERA_DE_PRODUCCION', false),
        'retencion_identificadores_dias' => (int) env('MEDICION_RETENCION_DIAS', 120),
        'timeout' => 5,
    ],

];
