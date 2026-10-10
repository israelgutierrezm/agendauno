# CI/CD

Cuatro flujos de GitHub Actions (`.github/workflows/`, ADR 0113). Ninguno publica en
producción ni en las tiendas por sí solo.

| Flujo | Cuándo | Qué hace |
|---|---|---|
| **CI** (`ci.yml`) | Cada PR y cada push a `main` | Revisa lo que cambió: API (avisos de seguridad de las dependencias con `composer audit`, Pint, PHPStan, Pest con MySQL, concurrencia), web (`npm audit` alto/crítico, lint, compilación, landings, pruebas), app (analyze, pruebas y los APK de los dos sabores) e imágenes (las cuatro, nginx, compose y el enrutamiento web → landings). En un PR solo corre lo de las carpetas que tocó; en `main`, todo. |
| **Imágenes** (`imagenes.yml`) | Push a `main`, etiquetas `v*` y a mano | Publica en GHCR `agendauno-api`, `agendauno-web`, `agendauno-landing-agendauno` y `agendauno-landing-turnouno` con la etiqueta del commit (y la `v*`). Inmutables: una etiqueta publicada no se reemplaza. |
| **Desplegar** (`desplegar.yml`) | Solo a mano, con aprobación | Entra por SSH al servidor del entorno (`staging` o `produccion`) y corre `actualizar.sh` o `volver.sh` (todo o `--solo` un componente); después prueba desde fuera los dos dominios. |
| **Apps móviles** (`apps-moviles.yml`) | Solo a mano | Compila el `.aab` firmado de AgendaUno o TurnoUno como artefacto. No lo sube a Play. |

## Detección de cambios

El trabajo `cambios` del CI compara el PR con su base (`git diff`) y enciende solo los
trabajos de lo que tocó:

| Área | Carpetas |
|---|---|
| API | `apps/api/` |
| Web | `apps/web/` |
| App | `apps/mobile/` |
| Imágenes | `apps/api/`, `apps/web/`, `infra/produccion/` |

Un cambio en `.github/workflows/` corre todo. Un PR solo de documentación no corre
nada más. Los trabajos que se saltan cuentan como aprobados para las reglas de la rama.

## Imágenes en GHCR

- Nombre: `ghcr.io/<dueño>/agendauno-<componente>:<commit corto>` (la misma `VERSION`
  que usa `actualizar.sh`) y, en una etiqueta de git, también `:vX.Y.Z`.
- La caché de construcción va en la etiqueta `:cache` de cada imagen (no es de
  despliegue).
- `web` y las landings llevan dentro sus variables públicas: se toman de las
  **variables del repositorio** (Settings → Secrets and variables → Actions →
  Variables): `DOMINIO`, `DOMINIO_TURNOUNO`, `VITE_RECAPTCHA_SITE_KEY` (obligatorias:
  sin `DOMINIO` ni la de reCAPTCHA no se publican), y opcionales
  `VITE_TURNOUNO_NOMBRE`, `VITE_GOOGLE_CLIENT_ID`, `VITE_ANALYTICS_ENDPOINT`,
  `VITE_VENTAS_WHATSAPP`, `GOOGLE_SITE_VERIFICATION`. Son las de **producción**: un
  servidor de staging con otros dominios construye sus propias imágenes (sin
  `REGISTRO`).
- En el servidor: `docker login ghcr.io` con un token de solo lectura de paquetes
  (`read:packages`) y `REGISTRO=ghcr.io/<dueño>` en `web.env`. Desde entonces
  `actualizar.sh` baja las imágenes del commit en lugar de construirlas (si el flujo
  «Imágenes» de ese commit no terminó, lo dice y no cambia nada).

## Desplegar

Cada servidor es un **entorno** de GitHub (Settings → Environments): `staging` y
`produccion`. Antes de cargarle secretos, en cada uno:

1. **Required reviewers**: quién aprueba cada despliegue (en producción, obligatorio).
2. **Deployment branches**: solo `main` (y etiquetas `v*` si se despliegan
   etiquetas).
3. Secretos:
   - `SSH_HOST`, `SSH_USUARIO`: el servidor y un usuario que pueda usar Docker.
   - `SSH_LLAVE`: una llave privada **solo para desplegar** (su pública en
     `~/.ssh/authorized_keys` de ese usuario).
   - `SSH_HUELLAS`: las líneas de `ssh-keyscan SERVIDOR` revisadas a mano (no se acepta
     un servidor desconocido).
   - `RUTA_PLATAFORMA`: la carpeta `infra/produccion` del clon en el servidor.

4. Variables: `URL_AGENDAUNO` y `URL_TURNOUNO` (`https://…` de cada producto en ese
   servidor), para las pruebas de después.

Actions → Desplegar → Run workflow: entorno, acción (`actualizar` o `volver`),
componente (`todo`, `web`, `landing-agendauno`, `landing-turnouno`) y ref (al
publicar, por omisión `origin/main`; al volver, la versión, o vacío para la anterior).
Sin los secretos el flujo se detiene antes de conectarse. Lo que hace en el servidor es
exactamente `actualizar.sh` o `volver.sh` (docs/DESPLIEGUE.md), con su mantenimiento,
respaldo, migraciones y revisiones; después pide desde fuera `/up`, `/robots.txt`, `/`
y `/entrar` de cada dominio y la salud de la API. La app de Flutter no tiene auditoría
automática de dependencias (`flutter pub outdated` a mano).

## Apps móviles

Un entorno por app: `android-agendauno` y `android-turnouno`, con:

- `ANDROID_KEYSTORE_BASE64` (`base64 -w0 subida.jks`), `ANDROID_STORE_PASSWORD`,
  `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`: su llave de subida de Google Play.
- `FIREBASE_API_KEY`, `FIREBASE_APP_ID`, `FIREBASE_SENDER_ID`, `FIREBASE_PROJECT_ID`:
  su app en el proyecto de Firebase (sin ellos, la app sale sin push).
- Variable `GOOGLE_SERVER_CLIENT_ID` (entrar con Google).

Actions → Apps móviles → Run workflow: la app y el número de compilación (mayor que el
último subido a Play). El `.aab` queda como artefacto 30 días; se sube a Play Console a
mano (requiere autorización). iOS: en una Mac con los esquemas de cada app
(docs/MOBILE.md).

## Pendiente de credenciales

- Variables del repositorio para las imágenes de `web` y las landings.
- Entornos `staging` y `produccion` con revisores y secretos SSH; servidor de staging.
- Entornos `android-*` con las llaves de subida y Firebase.
- Token de solo lectura de GHCR en el servidor.
