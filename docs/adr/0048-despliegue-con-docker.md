# ADR 0048 — Despliegue a producción con Docker

Estado: Aceptado (2026-09-26). Guía de operación: `docs/DESPLIEGUE.md`.

## Contexto

`infra/docker` solo tenía Redis para desarrollo y el CI solo prueba. Faltaba cómo
correr AgendaUno en un servidor sin depender de un proveedor concreto (aún no se
elige). Algunas reglas de la web deben respetarse al servirla:

- el sitio comercial prerenderizado no puede ser el respaldo universal;
- los subdominios de los negocios siempre reciben la aplicación;
- un recurso inexistente responde 404.

## Decisiones

- **Una imagen para la API** (PHP-FPM 8.3 con `pdo_mysql`, `pcntl`, `opcache` y el
  cliente de MySQL para los respaldos).
  - La misma imagen corre `queue:work` (los correos van en cola) y `schedule:work`.
  - El arranque crea la estructura de `storage` y cachea configuración, rutas y
    vistas con las variables del contenedor.
  - El worker y el scheduler corren como `www-data`.
- **Una imagen para la web**: compila con Node 22 (como el CI) y sirve `dist` con
  nginx. El dominio se inyecta al arrancar (plantilla).
  - En el dominio comercial, cada página con HTML propio se sirve tal cual y lo
    demás cae en `app.html`.
  - En `*.DOMINIO` siempre se sirve `app.html`.
  - `/assets` y los archivos con extensión que no existen dan 404.
- **Mismo origen para web y API**: nginx pasa `/api` y `/up` a PHP-FPM.
  - La web se compila con `VITE_API_URL` vacío (rutas relativas). Así cada negocio usa
    la API desde su propio subdominio sin CORS.
  - La API ya acepta `/api/v1/app/{slug}` en cualquier host.
- **TLS fuera del stack**: nginx escucha en `127.0.0.1:8080` detrás de un proxy con
  certificado comodín (Cloudflare Tunnel o Caddy con DNS).
  - nginx toma la IP real del cliente solo de la red confiable (`PROXY_CONFIABLE`),
    porque los límites por IP de la API dependen de ella.
  - Le pasa `HTTPS` a PHP según `X-Forwarded-Proto`.
- **MySQL administrado aparte**, una base por negocio (`TENANT_DB_DRIVER=mysql`). El
  usuario puede crear `tenant_%`.
- **Sin dependencias nuevas**: los archivos viven en el volumen `storage` (S3 requiere
  `league/flysystem-aws-s3-v3`), el correo usa SMTP y los logs salen por `stderr`.
- **Secretos fuera del repo**: `infra/produccion/*.env` está ignorado; solo se versionan
  las plantillas `*.env.example`.

## Consecuencias

- Elegir el proveedor es mecánico: un servidor con Docker, MySQL, DNS comodín y un
  proxy TLS.
- `APP_KEY` no puede cambiar: cifra las llaves de las pasarelas y firma los pases QR.
- Pendiente: S3 y monitoreo de errores (dependencias por aprobar), y el despliegue
  automático desde el CI.
