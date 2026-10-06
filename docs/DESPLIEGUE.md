# Despliegue a producción

Cómo poner AgendaUno en un servidor con Docker. Los archivos están en
`infra/produccion/`; las decisiones, en el ADR 0048.

## Qué corre

| Servicio | Qué hace |
|---|---|
| `web` | nginx: sitio comercial (HTML completo), la aplicación (`app.html`), pasa `/api` y `/up` a PHP y sirve `/storage` (logos y fotos). Escucha solo en `127.0.0.1:8080`. |
| `api` | Laravel en PHP-FPM. |
| `worker` | Cola en Redis: correos transaccionales. Chequeo de salud: su latido (`agendauno:latido --verificar=cola`). |
| `scheduler` | Tareas programadas (`routes/console.php`): recordatorios, renovaciones, agenda recurrente, cobros, outbox, respaldos, alertas… Chequeo de salud: su latido. |
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

   La aplicación trabaja en aislamiento `READ COMMITTED` (ADR 0052). Si el servidor
   guarda binlog, debe ser `binlog_format=ROW`, el valor por defecto de MySQL 8.

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
6. **Facturación (opcional)**: llave de FacturAPI (`FACTURAPI_LLAVE` o en la
   configuración del superadmin). Sin ella, en producción la facturación queda
   apagada: los negocios no emiten CFDI ni reciben la factura de su renta, y la
   pantalla lo dice. Nunca se simula un timbre fuera de desarrollo y pruebas.
   `agendauno:verificar-produccion` lo marca como aviso.

## Primera instalación

```bash
git clone https://github.com/israelgutierrezm/agendauno.git
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
   - `docker compose --env-file web.env exec api php artisan agendauno:verificar-produccion`
     termina en «Lista para producción» (dice qué falta si no);
   - `docker compose --env-file web.env exec api php artisan agendauno:verificar-concurrencia`
     termina en «Todo cuadró». Crea un negocio temporal con su base
     (`tenant_verificacion_*`), pone a competir procesos a la vez (el último lugar, el
     mismo horario de un profesional, cancelar y reprogramar), migra varios negocios,
     respalda y restaura uno, y borra todo al final. Tarda unos minutos. Repítelo si
     cambias de servidor o de versión de MySQL;
   - `curl -H "Host: DOMINIO" http://127.0.0.1:8080/up` responde 200;
   - `https://DOMINIO` muestra la portada y `https://DOMINIO/registro` el registro;
   - `docker compose --env-file web.env exec api php artisan agendauno:probar-correo tu@correo.com` llega;
   - `docker compose --env-file web.env logs -f worker scheduler` no muestra errores.
5. En el superadmin (`/plataforma`, con `PLATFORM_ADMIN_TOKEN`): tarifas del SaaS,
   parámetros de plataforma, la pasarela con la que cobras la renta y **los documentos
   legales**: llena los datos del responsable y publica el aviso de privacidad y los
   términos. En producción el registro de negocios está cerrado hasta que ambos estén
   publicados; cada negocio acepta la versión vigente y queda constancia.

## Actualizar

```bash
cd agendauno/infra/produccion
./actualizar.sh            # lo último de main
./actualizar.sh v1.4.0     # o una etiqueta / commit
```

`actualizar.sh`:

1. Construye la versión nueva con su propia etiqueta (el commit) sin tocar lo que está
   en marcha.
2. **Punto de corte.** Pone la aplicación en mantenimiento (no entran escrituras),
   detiene la cola y el programador **esperando a que terminen** lo que están haciendo
   (`stop_grace_period`: 5 min la cola, 15 min el programador) y libera los candados de
   tareas que se hubieran cortado. Recién entonces **respalda la plataforma y cada
   negocio**: el respaldo trae todo lo aceptado. Si el respaldo falla, reabre la
   versión anterior y no migra.
3. Migra (la plataforma y, con `agendauno:migrar-estudios`, cada negocio) y levanta la
   versión nueva **todavía en mantenimiento**.
4. **Solo la abre si atiende**: base, caché, esquema de la plataforma y de cada negocio
   al día (`agendauno:verificar-produccion --disponibilidad`) y una petición real por
   nginx. En mantenimiento ninguna tarea del negocio corre y la cola no toma trabajos,
   así que los procesos nuevos se comprueban con señales que no los reactivan:
   - el **programador** late también en mantenimiento (solo `agendauno:latido`);
   - el **worker** deja al arrancar su señal de arranque (`WorkerStarting`).

   Ambas señales llevan la versión (`APP_VERSION`, la pone `docker-compose.yml`): solo
   cuentan las de la versión nueva. Espera hasta 5 minutos; si no, la deja en
   mantenimiento, no la anota como actual y sale con error, con las opciones para
   corregir o volver.
5. Ya abierta, **confirma que la cola procesa** (el latido del minuto pasa por ella; hasta
   3 minutos) y corre `agendauno:verificar-produccion` completa. Si algo falta, lo dice y
   sale con error (código 2), sin «Listo».

La base nunca se revierte sola. Si una migración falla, todo queda en mantenimiento y el
script dice cómo reabrir la versión anterior o restaurar los respaldos recién tomados.
La primera vez que uses el script, la versión en marcha aún no tiene
`agendauno:respaldar-plataforma`: respalda MySQL con la herramienta del proveedor y corre
`SIN_RESPALDO_PLATAFORMA=1 ./actualizar.sh`.

### Volver a una versión anterior

```bash
./volver.sh                # a la versión de la que se vino
./volver.sh 3f4b7c9        # a una versión concreta (ver .historial-versiones)
```

Vuelve sin reconstruir: las imágenes de cada versión quedan en el servidor (bórralas a
mano cuando ya no las necesites: `docker image ls agendauno-*`). Igual que al actualizar,
pone mantenimiento, deja terminar la cola y el programador, solo reabre si la versión
atiende (con las mismas señales) y ya abierta confirma que la cola procesa; si no, sale
con error.
Solo cambia el código: las migraciones se escriben para que la versión anterior siga
funcionando con el esquema nuevo (primero se agrega; lo que se quita, en otra versión).
Si una actualización cambió datos de forma incompatible, restaura además el respaldo
que se tomó justo antes (ver «Datos y respaldos»).

## Datos y respaldos

Todo respaldo va al disco `RESPALDOS_DISCO`: en producción, un bucket compatible con S3
**fuera del servidor** (R2, B2, S3…; variables `AWS_*` en `api.env`). Cada archivo lleva
su suma sha256 al lado, que se comprueba antes de restaurar (un respaldo alterado no se
restaura).

| Qué | Cuándo | Comando |
|---|---|---|
| Base central de la plataforma (estudios, cobro del SaaS, configuración) | diario 03:05 y antes de cada actualización | `agendauno:respaldar-plataforma` |
| Archivos subidos (documentos, fotos, logos) | diario 03:05 | `agendauno:respaldar-plataforma` |
| Base de cada negocio | diario 03:15 y antes de cada actualización | `agendauno:respaldar-estudios` |
| Simulacro: restaura en lugares temporales el último respaldo de la plataforma, el de un negocio y el de los archivos, y comprueba que se podría volver a operar (tablas esenciales, todos los negocios del inventario del respaldo, un dueño activo y con acceso, relaciones sin huérfanos, una consulta real, archivos idénticos a los en uso) | domingos 04:30 | `agendauno:simulacro-restauracion` |

El respaldo de la plataforma guarda a su lado su inventario (`.inventario.json`): los
negocios que debe traer. El simulacro exige todos; también exige, en el negocio que
prueba, un dueño activo, sin baja y con contraseña o Google (de preferencia elige un
negocio que ya terminó su onboarding).

Se conservan `RESPALDOS_DIAS` días. Si un respaldo o el simulacro fallan, llega la
alerta por correo, y `agendauno:verificar-produccion` marca lo que esté viejo o sin
probar.

### Restaurar

```bash
docker compose --env-file web.env exec api php artisan down
# La base central (el más reciente o --respaldo=RUTA; --listar para ver cuáles hay):
docker compose --env-file web.env exec api php artisan agendauno:restaurar-plataforma --force
# Un negocio:
docker compose --env-file web.env exec api php artisan agendauno:restaurar-estudio SLUG --force
docker compose --env-file web.env exec api php artisan up
```

Los archivos subidos se restauran descomprimiendo `respaldos/_archivos/archivos-….tar.gz`
dentro del volumen `storage` (`private/` → `storage/app/private`, `public/` →
`storage/app/public`).

Además de esto, conviene que el proveedor de MySQL haga sus instantáneas diarias.

### Limpieza de registros técnicos

`agendauno:limpiar-registros` (diario, 03:40 de CDMX) borra los envíos y códigos de
WhatsApp, las sesiones para autorizar tarjetas y los errores que dejaron de pasar,
cuando ya cumplieron su plazo. Los plazos
se ajustan en el superadmin, «Parámetros» → «Limpieza de registros» (ADR 0079). No
borra historial de los negocios.

## Alertas y monitoreo

El superadmin (pestaña **Operación**) muestra lo mismo que la consola y los correos:
versión en marcha, si el servicio está abierto o en mantenimiento, el programador y
la cola, la verificación de producción completa, los últimos respaldos con el detalle
del último simulacro y las alertas de los últimos 30 días
(`GET /api/v1/plataforma/operacion`, solo lectura).

- `ALERTAS_CORREO` (en `api.env`) recibe cada 10 minutos, en un solo correo, lo que
  falló en la operación: errores de la aplicación (incluidos cobros, domiciliaciones y
  reembolsos), incidencias de cobro nuevas (pago tardío o duplicado, reembolso
  incierto), cobros confirmados por la conciliación porque su aviso no llegó (webhook
  de la pasarela mal configurado, ADR 0053), correos que agotaron sus intentos,
  webhooks de los negocios que dejaron de responder, respaldos fallidos, trabajos de
  la cola fallidos y la cola detenida. Lo
  que sigue pasando se vuelve a avisar a las 6 horas, no a cada vez.
- **Errores** (pestaña del superadmin, ADR 0080): los de la API, la web y la app,
  agrupados, con traza, contexto y versión, y lo sensible tachado. Se marcan
  resueltos o ignorados; uno resuelto que vuelve en otra versión se reabre y avisa.
  La web lleva la versión de la compilación (`VITE_APP_VERSION`, la misma `VERSION`
  de `actualizar.sh`); la app, la de `--dart-define=APP_VERSION`. Los errores de la
  web se traducen al archivo y la línea originales con los mapas de origen, que la
  imagen web deja en el volumen `mapas-web` (solo lo lee la API; no se publican ni se
  respaldan, ADR 0082).
- Si se detiene el **programador de tareas**, él mismo no puede avisar: registra
  `https://DOMINIO/api/v1/health?estricto=1` en un monitor externo (UptimeRobot,
  Better Stack…). Responde 503 si la base, la caché, el programador o la cola fallan.
- `agendauno:verificar-produccion` revisa la instalación completa (entorno, correo,
  latidos, respaldos fuera del servidor y recientes, alertas, aviso de privacidad,
  pasarela de la plataforma). Córrelo tras instalar y tras cada actualización (el
  script de actualizar lo hace). Distingue una instalación de prueba de la **apertura
  comercial**: con `APERTURA_COMERCIAL=true` en `api.env` (o `--apertura`) exige Stripe
  de la plataforma en modo live para cobrar la renta con dinero real; sin ella lo marca
  como aviso y termina en «Lista como instalación sin cobro real de la renta».

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
- Despliegue automático desde el CI (hoy el CI solo prueba).
