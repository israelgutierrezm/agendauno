# Despliegue a producción

Cómo poner AgendaUno en un servidor con Docker. Los archivos están en
`infra/produccion/`; las decisiones, en el ADR 0048.

## Qué corre

| Servicio | Qué hace |
|---|---|
| `web` | nginx: sitio comercial (HTML completo), la aplicación (`app.html`), pasa `/api` y `/up` a PHP y sirve `/storage` (logos y fotos). Escucha solo en `127.0.0.1:8080`. |
| `api` | Laravel en PHP-FPM. |
| `worker` | Cola en Redis: correos transaccionales. |
| `scheduler` | Tareas programadas (`routes/console.php`): recordatorios, renovaciones, agenda recurrente, cobros, outbox, respaldos… |
| `redis` | Caché, colas y sesiones. |

MySQL va aparte (servicio administrado o servidor propio). La web y la API comparten
dominio: cada negocio usa `https://{slug}.DOMINIO` y la app llama a `/api` ahí mismo.
Así no hay CORS entre subdominios.

## Antes de empezar

1. **Servidor** Linux con Docker Engine y el plugin de Compose (2 vCPU / 4 GB alcanzan
   para empezar).
2. **MySQL 8** con una base para el control plane y un usuario que pueda crear las bases
   de los negocios:

   ```sql
   CREATE DATABASE agendauno CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'agendauno'@'%' IDENTIFIED BY '…';
   GRANT ALL ON agendauno.* TO 'agendauno'@'%';
   GRANT ALL ON `tenant\_%`.* TO 'agendauno'@'%';
   ```

3. **DNS**: `DOMINIO`, `www.DOMINIO` y el comodín `*.DOMINIO` apuntando al servidor.
4. **HTTPS** delante de nginx, con certificado comodín (`*.DOMINIO` más `DOMINIO`). El
   comodín exige validación por DNS. Opciones:
   - **Cloudflare**: DNS con proxy más Cloudflare Tunnel (`cloudflared`) hacia
     `http://127.0.0.1:8080`. No abre puertos.
   - **Caddy** en el servidor, compilado con el módulo DNS de tu proveedor, con
     `reverse_proxy 127.0.0.1:8080`.

   El proxy debe mandar `X-Forwarded-For` y `X-Forwarded-Proto`, y redirigir HTTP a
   HTTPS. Con Cloudflare, pasa la IP real del cliente (`CF-Connecting-IP`) como
   `X-Forwarded-For`: los límites por IP de la API dependen de ella.
5. **Correo**: cuenta SMTP (Resend, Postmark, Amazon SES, Brevo…) con el dominio del
   remitente verificado (SPF y DKIM).

## Primera instalación

```bash
git clone https://github.com/israelgutierrezm/turnouno.git agendauno
cd agendauno/infra/produccion
cp api.env.example api.env
cp web.env.example web.env
```

1. Llena `web.env` (`DOMINIO`) y `api.env` (MySQL, correo, `APP_URL`,
   `APP_TENANT_DOMAIN`, `PLATFORM_ADMIN_TOKEN`…).
2. Genera la llave de la aplicación **una sola vez** y pégala en `APP_KEY`:

   ```bash
   docker compose --env-file web.env build
   docker compose --env-file web.env run --rm --no-deps --entrypoint php api artisan key:generate --show
   ```

   Guárdala también fuera del servidor. Si se pierde, las llaves de las pasarelas de
   cada negocio ya no se pueden leer.
3. Crea el esquema y arranca:

   ```bash
   docker compose --env-file web.env run --rm api php artisan migrate --force
   docker compose --env-file web.env up -d
   ```

4. Comprueba:
   - `curl -H "Host: DOMINIO" http://127.0.0.1:8080/up` responde 200;
   - `https://DOMINIO` muestra la portada y `https://DOMINIO/registro` el registro;
   - `docker compose --env-file web.env exec api php artisan turnouno:probar-correo tu@correo.com` llega;
   - `docker compose --env-file web.env logs -f worker scheduler` no muestra errores.
5. En el superadmin (`/plataforma`, con `PLATFORM_ADMIN_TOKEN`): tarifas del SaaS,
   parámetros de plataforma y la pasarela con la que cobras la renta.

## Actualizar

```bash
cd agendauno && git pull
cd infra/produccion
docker compose --env-file web.env build
docker compose --env-file web.env run --rm api php artisan migrate --force
docker compose --env-file web.env run --rm api php artisan turnouno:migrar-estudios --force
docker compose --env-file web.env up -d
```

`turnouno:migrar-estudios` aplica las migraciones nuevas a la base de cada negocio.
Si la de alguno falla, sigue con los demás, lista cuáles fallaron y termina con
error.

## Datos y respaldos

- **MySQL**: control plane y una base por negocio. Respáldalo con la herramienta del
  proveedor (instantáneas diarias y retención).
- **Volumen `storage`**: documentos, fotos, logos y los respaldos diarios por negocio
  (`turnouno:respaldar-estudios`, 03:15, se conservan `RESPALDOS_DIAS`). Cópialo fuera
  del servidor. Restaurar un negocio: `php artisan turnouno:restaurar-estudio`.
- Guardar archivos en S3 requiere instalar `league/flysystem-aws-s3-v3` (pendiente de
  aprobar). Mientras tanto viven en el volumen.

## Operación

- Logs: `docker compose --env-file web.env logs -f api worker scheduler web`.
- Mantenimiento: `docker compose … exec api php artisan down` / `up`.
- Nunca cambies `APP_KEY`.
- Tampoco cambies `APP_TENANT_DOMAIN` sin cambiar `DOMINIO`: los subdominios de los
  negocios dependen de ambos.
- App móvil: compila con `--dart-define=API_BASE_URL=https://DOMINIO` (más las
  variables de Firebase, ver `docs/MOBILE.md`) y publica en Google Play y App Store.

## Pendiente de decidir

- Proveedor del servidor, de MySQL y del correo.
- Almacenamiento S3 (dependencia nueva) y monitoreo de errores (Sentry u otro,
  también dependencia nueva).
- Despliegue automático desde el CI (hoy el CI solo prueba).
