# CI/CD

Cuatro flujos de GitHub Actions (`.github/workflows/`, ADR 0113). Ninguno publica en
producción ni en las tiendas por sí solo.

| Flujo | Cuándo | Qué hace |
|---|---|---|
| **CI** (`ci.yml`) | Cada PR y cada push a `main` | Revisa lo que cambió: API (avisos de seguridad de las dependencias con `composer audit`, Pint, PHPStan, Pest con MySQL, concurrencia), web (`npm audit` alto/crítico, lint, compilación, landings, pruebas), app (analyze, pruebas, los APK de los dos sabores y la marca blanca de ejemplo, que además comprueba que con el sabor de TurnoUno Gradle se niegue) e imágenes (las cuatro, nginx, compose y el enrutamiento web → landings). En un PR solo corre lo de las carpetas que tocó; en `main`, todo. |
| **Imágenes** (`imagenes.yml`) | Push a `main`, etiquetas `v*` y a mano | Publica en GHCR `agendauno-api`, `agendauno-web`, `agendauno-landing-agendauno` y `agendauno-landing-turnouno` con la etiqueta del commit (y la `v*`). Inmutables: una etiqueta publicada no se reemplaza. |
| **Desplegar** (`desplegar.yml`) | Solo a mano, con aprobación | Entra por SSH al servidor del entorno (`staging` o `produccion`) y corre `actualizar.sh` o `volver.sh` (todo o `--solo` un componente); después prueba desde fuera los dos dominios. |
| **Apps móviles** (`apps-moviles.yml`) | Solo a mano | Android: compila el `.aab` firmado de AgendaUno, TurnoUno o la marca blanca de un negocio como artefacto (no lo sube a Play). iOS (`plataforma: ios`): compila sin firma en macOS y revisa id, nombre, ícono, solo iPhone, el esquema de regreso del pago, el widget y el manifiesto de privacidad (ADR 0208). |

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
  `REGISTRO` y con `CONSTRUIR_EN_SERVIDOR=1`, ADR 0232).
- En el servidor: `docker login ghcr.io` con un token de solo lectura de paquetes
  (`read:packages`) y `REGISTRO=ghcr.io/<dueño>` en `web.env`, obligatorio en producción. Desde entonces
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

Un entorno de GitHub por app y plataforma:

| Entorno | Para | Lleva |
| --- | --- | --- |
| `android-agendauno`, `android-turnouno` | El `.aab` de cada app oficial | `ANDROID_KEYSTORE_BASE64` (`base64 -w0 subida.jks`), `ANDROID_STORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD` (su llave de subida de Google Play); `FIREBASE_API_KEY`, `FIREBASE_APP_ID` (o `FIREBASE_ANDROID_APP_ID`), `FIREBASE_SENDER_ID`, `FIREBASE_PROJECT_ID` (su app Android en Firebase); variable opcional `GOOGLE_SERVER_CLIENT_ID` (entrar con Google). |
| `marca-blanca-<negocio>` | El `.aab` de la app propia de un negocio de AgendaUno | Los cuatro `ANDROID_*` de SU llave de subida. Su nombre, su `ANDROID_ID` y su Firebase vienen de `configuraciones/marca_blanca/<negocio>.json`. |
| `ios-agendauno`, `ios-turnouno` | La compilación de iOS sin firmar | Variable `APP_STORE_ID` (el id numérico de su ficha en App Store Connect); `FIREBASE_IOS_API_KEY` (o `FIREBASE_API_KEY`), `FIREBASE_IOS_APP_ID`, `FIREBASE_SENDER_ID`, `FIREBASE_PROJECT_ID` (su app iOS en Firebase). |

Sin Firebase el flujo **falla** (antes solo avisaba): una app publicada sin push no
recibiría ningún aviso. Con `marca_blanca`, el trabajo `revisar` comprueba antes que el
slug sea válido, que sea AgendaUno y Android, que su archivo exista, que su `NEGOCIO`
coincida y que traiga su Firebase.

Actions → Apps móviles → Run workflow: la app, la plataforma, el número de compilación
(mayor que el último subido a la tienda) y, si es el caso, la marca blanca. El `.aab`
queda como artefacto 30 días; se sube a Play Console a mano (requiere autorización).
Con `plataforma: ios` se comprueba que compile con lo que llevará en el App Store, sin
firma: firmar y subir a App Store Connect es en una Mac con la cuenta de Apple
(docs/MOBILE.md, y `apps/mobile/ios/WIDGET.md` para el widget). La marca blanca no se
compila para iOS: el proyecto se niega (ADR 0208).

## Pendiente de credenciales

- Variables del repositorio para las imágenes de `web` y las landings.
- Entornos `staging` y `produccion` con revisores y secretos SSH; servidor de staging.
- Entornos `android-*`, `ios-*` y, por cada app propia, `marca-blanca-<negocio>`, con
  las llaves de subida, el id del App Store y Firebase.
- Token de solo lectura de GHCR en el servidor.
