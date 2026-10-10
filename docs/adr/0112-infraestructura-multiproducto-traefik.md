# ADR 0112 — Infraestructura multiproducto: imágenes por componente y Traefik

Estado: Aceptado (2026-10-10). Parte del ADR 0108 (plataforma multiproducto).

## Contexto

La plataforma sirve dos dominios (agendauno.mx y turnouno.mx, cada uno con el
subdominio de sus negocios) desde una sola instalación de Docker. Hasta ahora una sola
imagen web llevaba la aplicación y las dos landings: corregir un texto de la landing de
TurnoUno obligaba a publicar todo. El servidor de producción (un VPS de IONOS) ya tiene
un Traefik que atiende otros sitios: no se instala otro. El DNS está en Cloudflare.

## Decisión

- **Una imagen por componente web.** `web` (nginx: la aplicación, `/api` a PHP-FPM,
  `/storage`, la entrada de los dos dominios), `landing-agendauno` y
  `landing-turnouno` (`landing.Dockerfile` con `PRODUCTO`: solo su portada, sus
  páginas por giro, su sitemap, su robots y `/assets-{producto}/`). nginx de `web`
  pasa esas rutas a la landing de su dominio por la red interna; una página que la
  landing no tiene, o la landing caída, muestran la aplicación (nunca un 502 en la
  portada). Las landings no se exponen.
- **Publicar por componente.** `./actualizar.sh --solo web|landing-agendauno|
  landing-turnouno [REF]` construye y cambia solo ese contenedor, sin mantenimiento,
  respaldos ni migraciones (no tocan datos); si no atiende, deja el anterior.
  `./volver.sh --solo …` regresa uno. Cada componente guarda su versión
  (`.version-web`, `.version-landing-*`); la plataforma (api, worker, scheduler), en
  `.version-actual`. `./actualizar.sh` sin `--solo` publica todo como antes (y además
  revisa que cada landing responda). `./compose.sh` es `docker compose` con la versión
  en marcha de cada componente, para cualquier `up` a mano.
- **Traefik existente.** `docker-compose.traefik.yml` (en `COMPOSE_FILE` de `web.env`)
  conecta solo `web` a la red de Traefik con dos routers (uno por dominio: el dominio y
  `HostRegexp` de un nivel de subdominio) y pide a su resolvedor un certificado comodín
  por dominio (`DOMINIO` + `*.DOMINIO`), que solo se valida por DNS-01: el Traefik debe
  tener un resolvedor ACME con Cloudflare (`CF_DNS_API_TOKEN`, permiso DNS:Edit de las
  dos zonas). `web` sigue en `127.0.0.1:8080` (solo el servidor) para las revisiones de
  los scripts. Escrito para Traefik 3 (`HostRegexp` con expresión regular,
  `ipallowlist`).
- **IP real con Cloudflare.** Con el proxy de Cloudflare, `docker-compose.cloudflare.yml`
  hace que Traefik solo acepte las IP publicadas de Cloudflare en estos routers y que
  nginx tome la IP del cliente de `CF-Connecting-IP`; sin el proxy, de
  `X-Forwarded-For` (lo pone Traefik). `PROXY_CONFIABLE` es la subred de la red de
  Traefik: nginx solo cree las cabeceras de quien llega por ahí.

## Consecuencias

- Una corrección de una landing se publica en segundos y no reinicia la aplicación ni
  la API. El nginx de `web` sigue siendo la única entrada: un cambio de sus reglas se
  publica con `--solo web`.
- La versión de cada componente puede diferir; `.historial-versiones` anota cada
  cambio con su componente.
- Los rangos de Cloudflare están en el archivo: si Cloudflare publica otros, hay que
  actualizarlo (una IP nueva fuera de la lista recibiría 403).
- Cambiar la configuración del Traefik existente (resolvedor DNS-01, token de
  Cloudflare) y apuntar el DNS es parte del despliegue y requiere autorización.

## Pendiente

- Registro de imágenes (GHCR) y despliegue automático desde el CI (fase de CI/CD).
- Traefik 2: las reglas cambian (`HostRegexp(`{sub:[a-z0-9-]+}.dominio`)`,
  `ipwhitelist`); documentado, no automatizado.
