# Entrega: plataforma multiproducto AgendaUno y TurnoUno

Fecha: 2026-10-10. Ramas `multiproducto/fase-1` … `multiproducto/fase-7` (una por fase,
apiladas; cada una con su PR a `main`). Decisiones: ADR 0108 a 0113. Estado vivo por
componente: `docs/MULTIPRODUCTO.md`.

Lo que dice «probado» aquí se ejecutó de verdad (pruebas automáticas o revisión manual
indicada); lo que no se pudo ejecutar se dice como pendiente.

## 1. Arquitectura anterior

- Una marca (AgendaUno) para negocios de clases y de citas, en `agendauno.mx` y
  `{slug}.agendauno.mx`.
- Laravel monolito modular: base central (`estudios`, cobro SaaS, superadmin) y una base
  por negocio; modalidad excluyente por negocio (clases o citas, ADR 0104).
- Una web Vue (`apps/web`) con landing, registro, panel, portal del cliente,
  escaparate y superadmin; una imagen Docker web con todo.
- Una app Flutter (`com.agendauno.app`).
- Docker en un servidor: nginx en `127.0.0.1:8080` detrás de un proxy TLS (Caddy o
  Cloudflare Tunnel); `actualizar.sh`/`volver.sh` construían todo en el servidor.
- CI: API, web, app e imágenes en cada cambio.

## 2. Arquitectura nueva

- **Una plataforma, dos productos** (ADR 0108). El producto sale de la modalidad:
  clases → **AgendaUno** (`agendauno.mx`), citas → **TurnoUno** (`turnouno.mx`). Una
  API, una base central, una base por negocio, un superadmin, un cobro SaaS, una
  autenticación.
- Cada negocio solo se abre en el dominio de su producto (`{slug}.agendauno.mx` o
  `{slug}.turnouno.mx`) y en la app de su producto (`X-App-Producto`).
- **Web: un código, tres builds** (ADR 0109): la aplicación (consola compartida) y una
  landing por producto, cada una en su imagen (ADR 0112).
- **PWA por negocio** en su subdominio, para los dos productos (ADR 0110).
- **Dos apps oficiales** desde un proyecto Flutter (sabores) y **marca blanca**
  preparada solo para AgendaUno (ADR 0111).
- **Infraestructura**: el Traefik existente del VPS, certificados comodín por DNS-01
  en Cloudflare, publicación por componente (ADR 0112).
- **CI/CD** por áreas, imágenes inmutables en GHCR, despliegue manual con aprobación
  (ADR 0113).

```
Internet → Cloudflare (DNS, proxy) → Traefik (existente) → web (nginx)
   agendauno.mx  /, /software-para-*, sitemap, robots → landing-agendauno
   turnouno.mx   /, /software-para-*, sitemap, robots → landing-turnouno
   lo demás y *.agendauno.mx / *.turnouno.mx → aplicación (panel, portal, escaparate, PWA)
   /api → api (PHP-FPM) ── worker, scheduler (misma imagen) ── Redis, MySQL (aparte)
```

## 3. Estructura final del repositorio

```
apps/api/                 Laravel (Tenancy, Platform); ProductoComercial, MarcaProducto
apps/web/                 Vue: dist/app (aplicación), dist/agendauno, dist/turnouno
  scripts/                build-app, build-marketing <producto>, check-marketing(-http)
apps/mobile/              Flutter: sabores agendauno y turnouno
  android/app/src/turnouno/   íconos y color de TurnoUno
  configuraciones/marca_blanca/  un archivo por app de marca blanca (ejemplo.json)
infra/produccion/         compose (+ traefik, cloudflare), api/web/landing.Dockerfile,
                          nginx, actualizar.sh, volver.sh, compose.sh, versiones.sh
.github/workflows/        ci, imagenes, desplegar, apps-moviles
docs/                     MULTIPRODUCTO, DESPLIEGUE, CI-CD, MOBILE, ADR 0108–0113
```

Un solo repositorio, sin ramas permanentes por producto.

## 4. Cambios principales

| Fase | Commit | Qué |
|---|---|---|
| 1 | `460d443` | La API atiende AgendaUno y TurnoUno: producto, dominios, correos, registro por producto, interesados |
| 2 | `63911cf` | Web: un código, tres builds; landing de cada producto; marca por dominio |
| 3 | `a1a3ff4` | PWA de cada negocio (manifiesto dinámico, service worker, color de marca) |
| 4 | `e99f83a` | Apps oficiales por sabor; la API rechaza negocios del otro producto; marca blanca |
| 5 | `525a481` | Imágenes por componente, publicar/volver por componente, Traefik y Cloudflare |
| 6 | `b8f2139`, `b470a29` | CI por áreas, auditoría de dependencias, GHCR, desplegar y apps firmadas |
| 7 | `c50697a` | Producto en los registros, diseño de dominios propios, este informe |
| 8 | (esta) | Constructor del sitio de cada negocio (ADR 0114) |

## 5. Aplicaciones web separadas

Decisión aprobada: **un código, tres builds** (no tres proyectos). `npm run build`
produce `dist/app` (la aplicación), `dist/agendauno` y `dist/turnouno` (landings con
HTML completo pre-generado, recursos en `/assets-{producto}/`). En producción son tres
imágenes (`web`, `landing-agendauno`, `landing-turnouno`): nginx de `web` pasa a cada
landing su portada, sus páginas por giro, su sitemap, su robots y sus recursos; una
página que la landing no tiene, o la landing caída, muestran la aplicación. Cada una se
publica sola (`./actualizar.sh --solo landing-turnouno`).

## 6. Landing AgendaUno

`agendauno.mx`: solo la propuesta de clases (estudios boutique, gimnasios, academias,
natación), con sus páginas `software-para-*` de giros de clases, precios, registro
abierto (`registro.abierto_agendauno`). SEO propio: título, descripción, Open Graph,
canonical, datos estructurados, sitemap y robots de su dominio. Se conservan logotipo,
colores y lema. Pendiente de despliegue.

## 7. Landing TurnoUno

`turnouno.mx`: solo la propuesta de citas, **lanzada** junto con AgendaUno (decisión
del 2026-10-10): prueba gratis y registro abiertos (`registro.abierto_turnouno = 1`). Si
el superadmin cierra el registro de un producto, su landing cambia sola a «Quiero que
me avisen» (lista de interesados con reCAPTCHA, visible en el superadmin).
Su identidad es configurable (`VITE_TURNOUNO_NOMBRE`, logotipo en texto; no se inventó
un logotipo). SEO propio, sin contenido duplicado con AgendaUno. Pendiente de despliegue.

## 8. Consola compartida

La aplicación (`dist/app`) es la consola de los dos productos: panel del negocio,
portal del cliente, escaparate y superadmin (uno solo). Se presenta con la marca del
dominio en que se abre (textos, logo, enlaces) y su registro ofrece solo los giros de
ese producto. Se publica con `--solo web` sin tocar la API.

### Sitio de cada negocio (fase 8)

Configuración → «Sitio web» (ADR 0114): plantillas (esencial, portada, compacta),
secciones que se muestran, ocultan y reordenan (portada primero y contacto al final),
títulos y textos propios, la foto de «Nosotros», banners con fechas y botón, vista
previa en ancho de teléfono o computadora, publicar y descartar. Lo operativo (horarios,
precios, servicios, equipo, sedes) sigue saliendo del sistema. Probado: 9 pruebas del
API, 13 de la web y en el navegador contra la API local (guardar, vista previa,
plantilla, publicar, 360 px sin desbordes).

## 9. Cambios backend

- `ProductoComercial` (de la modalidad) y `MarcaProducto` (URL web, URL API,
  remitente); `/yo` y `/marca` dicen el producto.
- Rutas por subdominio para cada producto; `ResolverEstudio` abre cada negocio solo en
  el dominio de su producto y solo en la app de su producto (`X-App-Producto`); una app
  de marca blanca solo abre su negocio y solo de AgendaUno (`X-App-Negocio`).
- Correos, avisos, regresos de pago y calendarios con la URL y la marca del producto;
  remitente por producto opcional.
- Registro por producto (`registro.abierto_*`), lista de interesados, slugs reservados,
  directorio por producto.
- Manifiesto de la PWA por negocio y `color_marca`.
- Los registros (logs) llevan el negocio y su producto.

## 10. Arquitectura multiproducto

El producto no se guarda aparte: sale de la modalidad, que es excluyente (ADR 0104), así
que no puede contradecirla y no hubo migración de datos. Un solo motor de cobro SaaS con
planes distintos por producto (alumnos activos para AgendaUno; plan por nivel y
profesionales para TurnoUno, ADR 0107); precios sin cambios. Un negocio con los dos
productos queda preparado para después (hoy, una modalidad por negocio).

## 11. PWA AgendaUno y 12. PWA TurnoUno

Una sola implementación para los dos productos (ADR 0110): en el subdominio de cada
negocio, manifiesto dinámico (`/api/v1/pwa/manifest.webmanifest`) con su nombre,
nombre corto, descripción, logo, color de marca e íconos del producto; service worker
propio que nunca guarda `/api` ni `/storage` y sin conexión muestra «Sin conexión»
(nunca una reserva falsa); invitación a instalar (Android/Chrome y, en iPhone, cómo
«Agregar a inicio»). Probado: pruebas del API (manifiesto, 404 en el dominio del otro
producto) y de la web (`pwa.spec.ts`), y el manifiesto contra el API local con el host
de un negocio de TurnoUno. Pendiente: probar la instalación en teléfonos reales con
HTTPS (requiere despliegue). Las notificaciones push web no están implementadas.

## 13. Flutter AgendaUno y 14. Flutter TurnoUno

Un proyecto, dos sabores de Android (ADR 0111): `agendauno` (por omisión,
`com.agendauno.app`, API `agendauno.mx`) y `turnouno` (`com.turnouno.app`, API
`turnouno.mx`, ícono provisional con su inicial). `ProductoApp` en Dart da nombre,
dominio e id de cada app. Cada petición manda `X-App-Producto`; si alguien escribe un
negocio del otro producto, la app le dice cuál descargar. Push: un proyecto de Firebase
con las dos apps. Probado: `flutter analyze` sin avisos, 209 pruebas, APK de depuración
de los dos sabores compilados y revisados con `aapt` (id y nombre). **iOS no se compiló**
(no hay Mac): el producto en Dart está listo, faltan los esquemas de Xcode
(docs/MOBILE.md).

## 15. White-label preparado

Solo AgendaUno, sin publicar. Una app de marca blanca es el sabor `agendauno` con el
archivo de su negocio (`configuraciones/marca_blanca/<negocio>.json`: `NEGOCIO`,
`APP_NOMBRE`, `ANDROID_ID`, Firebase, App Store) y sus íconos opcionales. Abre directo
en su negocio y la API solo le abre ese negocio. Gradle se niega a compilarla con el
sabor de TurnoUno o sin id propio. Probado: pruebas del API y de Flutter, y la
compilación del ejemplo (`mx.ejemplo.estudio`, «Estudio Ejemplo») más los dos rechazos
de Gradle. Falta: alta y estado de las marcas blancas en el superadmin, y su precio.

## 16. Configuración de dominios

| Dónde | Variable | AgendaUno | TurnoUno |
|---|---|---|---|
| `api.env` | dominio | `APP_TENANT_DOMAIN` | `TURNOUNO_DOMINIO` |
| `api.env` | web | `APP_SPA_URL` | `TURNOUNO_URL_WEB` |
| `api.env` | remitente (opcional) | `AGENDAUNO_MAIL_FROM` | `TURNOUNO_MAIL_FROM` |
| `web.env` | dominio | `DOMINIO` | `DOMINIO_TURNOUNO` |
| `web.env` | nombre (opcional) | — | `VITE_TURNOUNO_NOMBRE` |

nginx tiene un servidor por dominio y uno para los subdominios; Traefik, un router por
dominio. Los dominios propios de los negocios están diseñados pero no habilitados
(docs/MULTIPRODUCTO.md).

## 17. Docker y Traefik

Servicios: `api`, `worker`, `scheduler` (una imagen), `web`, `landing-agendauno`,
`landing-turnouno`, `redis`; MySQL aparte. Solo `web` entra a la red de Traefik
(`docker-compose.traefik.yml`, sin instalar otro Traefik) con dos routers y un
certificado comodín por dominio por DNS-01 de Cloudflare; con el proxy de Cloudflare,
`docker-compose.cloudflare.yml` deja entrar solo a Cloudflare y toma la IP real de
`CF-Connecting-IP`. PHP-FPM, Redis y las landings no se exponen; `web` sigue en
`127.0.0.1:8080` para las revisiones de los scripts. Verificado aquí: `docker compose
config` con las tres combinaciones, sintaxis de los scripts, simulación de las
versiones por componente. **No verificado aquí**: construir las imágenes, `nginx -t` y
el enrutamiento real (no hay Docker en esta máquina); están en el CI y corren al abrir
el PR.

## 18. CI/CD

- `ci.yml`: solo corre lo que cambió (API, web, app, imágenes); auditoría de
  dependencias (`composer audit`, `npm audit`), lint, análisis estático, pruebas,
  concurrencia en MySQL, builds (web, landings, APK de los dos sabores), imágenes,
  `nginx -t`, compose y enrutamiento con contenedores.
- `imagenes.yml`: imágenes inmutables por commit en GHCR.
- `desplegar.yml`: manual, con aprobación del entorno; `actualizar.sh` o `volver.sh`
  por SSH y pruebas desde fuera.
- `apps-moviles.yml`: `.aab` firmado por app como artefacto; no sube a tiendas.

Detalle, secretos y entornos: `docs/CI-CD.md`.

## 19. Migraciones

Dos de la base central, solo para agregar (compatibles con la versión anterior):

- `2026_10_10_000100_interesados_por_producto`: tabla `interesados`.
- `2026_10_10_000200_color_de_marca_de_los_negocios`: `estudios.color_marca`
  (nullable).

Ninguna en las bases de los negocios; no se movieron ni borraron datos, ni se tocaron
saldos. Aplicadas en la base de desarrollo.

## 20. Pruebas ejecutadas

| Qué | Cómo | Resultado |
|---|---|---|
| API: multiproducto, PWA, apps y marca blanca | Pest (`MultiproductoTest`, `PwaNegocioTest`, `AppsMovilesTest`) | 13 pruebas, 165 aserciones, verdes |
| API completa | Pest en paralelo (SQLite), commit `e99f83a` | Verde: 1167 pruebas, 19 582 aserciones, sin fallos (104 min, compartiendo la máquina con las compilaciones) |
| API: estilo y tipos de lo cambiado | Pint y PHPStan | Sin errores |
| Web | `npm run lint`, `npm run build` (con vue-tsc), `npm run test:marketing` (dos landings y por HTTP), vitest | Todo verde: lint, build, las dos landings (5 páginas cada una: HTML sin JS, canonical, Open Graph, JSON-LD) y 10 páginas por HTTP; vitest 876 pruebas en 161 archivos |
| Flutter | `flutter analyze`, `flutter test` | Sin avisos; 209 pruebas verdes |
| Android | APK de `agendauno`, `turnouno` y marca blanca de ejemplo; `aapt` | Ids y nombres correctos; Gradle rechaza marca blanca en TurnoUno y sin id propio |
| Revisión manual (fases 2 y 3) | Navegador contra la API local | Las dos landings, interesados de punta a punta, superadmin, manifiesto por host |
| Compose y scripts | `docker compose config` (3 combinaciones), `sh -n`, simulación de `versiones.sh` | Correctos |
| Imágenes, nginx y enrutamiento con contenedores | CI (`imagenes`) | **No ejecutado aún**: corre al abrir el PR |
| iOS, teléfonos reales, Traefik y DNS reales, certificados, cobro en vivo | — | **Pendiente**: requiere Mac, despliegue o credenciales |

## 21. Fallos encontrados y corregidos

- La landing de TurnoUno mostraba «AgendaUno» en textos y en la demo del producto.
- nginx: la regla de archivos `.txt`/`.xml` ganaba al robots y sitemap de cada landing
  (ahora son ubicaciones exactas).
- Pruebas de Laravel: tras pedir una URL con host, las siguientes peticiones relativas
  heredaban ese host.
- Vitest no veía `vi.stubEnv` a través de un alias de `import.meta`.
- Gradle (Kotlin): `java.util` se resolvía como la extensión `java` del proyecto.
- La guarda de marca blanca miraba todo el grafo de tareas (incluía tareas de TurnoUno
  al compilar AgendaUno); ahora mira lo que se pidió compilar.
- `docs/DESPLIEGUE.md` no pedía registrar `turnouno.mx` en reCAPTCHA ni en los orígenes
  de Google.

## 22. Riesgos pendientes

- **Negocios de citas ya existentes**: desde la fase 1 viven en `{slug}.turnouno.mx` y
  en la app de TurnoUno. Si alguno opera en producción con enlaces o la app de
  AgendaUno, esos enlaces dejan de abrirlo: antes de desplegar, confirmar que no hay
  negocios de citas en producción o avisarles su nueva dirección.
- La infraestructura nueva (imágenes, nginx, enrutamiento) solo se valida en el CI del
  PR; el primer despliegue debe hacerse con respaldo y `volver.sh` a mano.
- Traefik: escrito para la versión 3; con la 2 cambian las reglas.
- `PROXY_CONFIABLE` debe ser la subred de la red de Traefik: si no, la API vería la IP
  de Traefik y los límites por IP serían de todos a la vez.
- La lista de IP de Cloudflare está en el archivo y hay que mantenerla.
- Las imágenes de GHCR llevan los dominios de producción: staging construye las suyas.
- TurnoUno sin logotipo ni íconos definitivos; una sola versión mínima para todas las
  apps.

## 23. Variables que debo configurar

- `api.env`: `TURNOUNO_DOMINIO`, `TURNOUNO_URL_WEB`, opcionales `TURNOUNO_NOMBRE`,
  `AGENDAUNO_MAIL_FROM`, `TURNOUNO_MAIL_FROM`; `FRONTEND_URL` si hay orígenes extra.
- `web.env`: `DOMINIO`, `DOMINIO_TURNOUNO`, `VITE_RECAPTCHA_SITE_KEY`; con Traefik
  `COMPOSE_FILE`, `TRAEFIK_RED`, `TRAEFIK_ENTRADA`, `TRAEFIK_CERTRESOLVER`,
  `PROXY_CONFIABLE`; opcional `REGISTRO` (imágenes del CI).
- Traefik: resolvedor ACME DNS-01 de Cloudflare y `CF_DNS_API_TOKEN` (DNS:Edit de las
  dos zonas).
- GitHub: variables del repositorio para las imágenes; entornos `staging`,
  `produccion` (revisores, secretos SSH, `URL_AGENDAUNO`, `URL_TURNOUNO`),
  `android-agendauno`, `android-turnouno` (llave de subida, Firebase).
- Superadmin: `registro.abierto_agendauno` = 1 y `registro.abierto_turnouno` = 0 hasta
  el lanzamiento.
- reCAPTCHA: agregar `turnouno.mx` al sitio. Google OAuth: origen
  `https://turnouno.mx`.

## 24. DNS pendientes

En Cloudflare, en cada zona (`agendauno.mx`, `turnouno.mx`): `A` (y `AAAA` si hay IPv6)
de `@`, `www` y `*` al VPS; proxy (nube naranja) recomendado; SSL/TLS **Full (strict)**.
SPF y DKIM del remitente de cada producto si se usa uno propio. Requiere autorización.

## 25. Certificados pendientes

Uno comodín por dominio (`agendauno.mx` + `*.agendauno.mx`; `turnouno.mx` +
`*.turnouno.mx`; `www` queda cubierto), emitidos por el Traefik existente por DNS-01
con Cloudflare. Con el proxy de Cloudflare, el certificado de su borde lo emite
Cloudflare. Nada se emitió todavía.

## 26. Pasos para desplegar AgendaUno

1. Respaldar MySQL y el volumen `storage` del servidor.
2. DNS de `agendauno.mx` (punto 24) y resolvedor DNS-01 en Traefik (punto 25).
3. `web.env` y `api.env` (punto 23); `COMPOSE_FILE` con Traefik (y Cloudflare).
4. `./actualizar.sh` (o «Desplegar» en GitHub, entorno `produccion`, acción
   `actualizar`, componente `todo`): migra, revisa y abre.
5. Revisar `https://agendauno.mx`, `/registro`, un `{slug}.agendauno.mx` y
   `agendauno:verificar-produccion`.

## 27. Pasos para desplegar TurnoUno

Es la misma instalación: con el paso anterior ya queda desplegada. Además:

1. DNS de `turnouno.mx` (punto 24); reCAPTCHA y Google con `turnouno.mx`.
2. Confirmar en el superadmin que `registro.abierto_turnouno = 1` (abierto) y que la
   landing ofrece la prueba gratis y el registro.
3. Cambios solo de su landing: `./actualizar.sh --solo landing-turnouno`.
4. Para pausar el alta de negocios: cerrar su registro en el superadmin (sin
   desplegar); la landing pasa a la lista de interesados.

## 28. Publicar AgendaUno Android/iOS y 29. Publicar TurnoUno Android/iOS

Android (igual para las dos, cada una con su ficha en Play Console):

1. Llave de subida propia y su entorno `android-<app>` en GitHub (docs/CI-CD.md).
2. Su app en Firebase (`com.agendauno.app` / `com.turnouno.app`).
3. «Apps móviles» → app y número de compilación → descargar el `.aab`.
4. Subirlo a Play Console (prueba interna primero). Requiere autorización.

iOS (en una Mac con Xcode y cuenta de Apple Developer):

1. Crear las configuraciones y esquemas `agendauno` y `turnouno` (docs/MOBILE.md), con
   `com.turnouno.app` y su nombre en las de TurnoUno.
2. Capacidad Push Notifications y llave APNs en Firebase.
3. `flutter build ipa --flavor <app> --dart-define=APP_STORE_ID=…` y subir con Xcode o
   Transporter. Requiere autorización.

TurnoUno necesita antes su logotipo e íconos definitivos.

## 30. Procedimiento para una futura app white-label

1. Autorización y contrato con el negocio (de AgendaUno).
2. Copiar `configuraciones/marca_blanca/ejemplo.json` a `<negocio>.json`: `NEGOCIO`
   (slug), `APP_NOMBRE`, `ANDROID_ID` propio, `APP_STORE_ID` y su app en Firebase.
3. Íconos en `configuraciones/marca_blanca/<negocio>/android/res`.
4. `flutter build appbundle --flavor agendauno
   --dart-define-from-file=configuraciones/marca_blanca/<negocio>.json` con su llave
   de subida.
5. Publicar en la cuenta que corresponda (requiere autorización).

## 31. Procedimiento de rollback

- Todo: `./volver.sh [VERSION]` (o «Desplegar» con acción `volver`): mantenimiento,
  versión anterior de la plataforma y la aplicación web, revisión antes de abrir.
- Un componente: `./volver.sh --solo landing-turnouno [VERSION]` (sin mantenimiento).
- La base no se revierte sola: las migraciones son compatibles hacia atrás; si un
  cambio de datos no lo fuera, se restauran los respaldos tomados al actualizar
  (docs/DESPLIEGUE.md → Restaurar).
- Apps: las tiendas no regresan versiones; se publica una nueva y, si hace falta, se
  sube `app.version_minima`.

## 32. Estado de preparación comercial

- **AgendaUno**: listo para lanzar en cuanto se despliegue y se configuren DNS,
  certificados y credenciales: landing, registro abierto, consola, PWA y app Android
  (por publicar).
- **TurnoUno**: se lanza junto con AgendaUno (2026-10-10): landing, registro abierto,
  consola, PWA y app Android (por publicar). Falta su logotipo definitivo (hoy en texto
  e íconos provisionales) y publicar su app en las tiendas.

## Matriz de estado

Ver la tabla completa y vigente en `docs/MULTIPRODUCTO.md` → «Estado por componente».
Resumen:

| Estado | Componentes |
|---|---|
| Implementado y probado | Constructor del sitio de cada negocio (fase 8, ADR 0114); Producto por modalidad; dominios por producto en la API; correos y enlaces por producto; registro por producto y cierre de TurnoUno; interesados; directorio y slugs reservados; un código y tres builds web; consola con la marca del dominio; superadmin de interesados; color de marca; apps Android de los dos productos; la API rechaza negocios del otro producto |
| Implementado, pendiente de credenciales | Imágenes en GHCR; despliegue desde GitHub; compilación firmada de las apps |
| Implementado, pendiente de despliegue | Landings de AgendaUno y TurnoUno; imágenes web separadas; publicar y volver por componente; PWA por negocio; Traefik y certificados comodín; IP real con Cloudflare; CI por áreas |
| Preparado para futuro | Apps en iOS e iOS en el CI; marca blanca; dominios propios; íconos cuadrados del logo; negocio con los dos productos; documentos legales por producto |
| No implementado | Push web |
| Requiere autorización | DNS; cambios en el Traefik y en producción; publicar apps; autorregistro de clientes (se mantiene cerrado) |
