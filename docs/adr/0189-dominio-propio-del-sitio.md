# ADR 0189 — Dominio propio del sitio

Estado: Aceptado (2026-10-20). Sin validar en producción.

## Contexto

El sitio de cada negocio vive en el subdominio de su producto (`{slug}.agendauno.mx`,
`{slug}.turnouno.mx`, ADR 0108). El comparativo lo marcó como pendiente: la
competencia permite publicar el sitio en el dominio del negocio, y el panel decía
«Plus, aún no disponible».

En producción, nginx ya atiende cualquier host con la aplicación y pasa `/api` a PHP
en el mismo dominio. La página pública llega con su cabecera y su contenido para los
buscadores por SSI, y la API resuelve el negocio por el host (ADR 0128 y 0155). Lo que
falta es saber de qué negocio es un dominio ajeno, que el dueño pruebe que es suyo y
darle un certificado. El resolvedor de Traefik por DNS-01 con Cloudflare no sirve: el
dominio no es nuestro.

## Decisión

- **Uno por negocio, en el control plane** (`dominios_estudio`). Un dominio no puede
  ser de dos negocios ni de la plataforma (los dominios de producto y sus
  subdominios), ni de una terminación que no es de internet (`.test`, `.local`…). Lo
  que escribe el dueño se normaliza: sin esquema, ruta, puerto ni punto final.
- **Es del sitio avanzado** (`sitio_avanzado`, «Plus», ADR 0167). Sin él no se conecta.
  Si el negocio lo pierde, el dominio deja de servirse (no se borra): queda `sin_plan`.
- **Que es suyo, con un TXT** en `_agendauno.{dominio}` con
  `agendauno-verificacion={token}`. Queda probado una vez.
- **Que apunta, con un CNAME** a `sitios.{dominio de su producto}` (o el fijado en
  `DOMINIOS_PROPIOS_DESTINO`). En la raíz de su dominio, donde muchos proveedores no
  admiten CNAME, se le piden registros A a `DOMINIOS_PROPIOS_IPS`. Todas sus IPs deben
  ser nuestras. Que apunta se revisa siempre: si deja de apuntar, deja de servirse.
- **Revisión.** El dueño la pide en el panel; además, `agendauno:dominios-propios` (cada
  10 minutos) revisa los pendientes y, cada 6 horas, los activos. La consulta DNS va
  detrás de `ConsultaDns`, que las pruebas fijan.
- **Dónde se usa.**
  - `GET /api/v1/dominio-propio`, sin sesión, dice el negocio del host con que se
    abrió la página.
  - La web lo pregunta al cargar en un host que no es de la plataforma, antes de su
    primera ruta, y ahí lo trata como su subdominio: marca, app instalable y entrar.
  - La página para buscadores (cabecera, contenido, sitemap, robots, 404) lo resuelve
    igual.
  - La canónica y el sitemap del negocio apuntan a su dominio, también en el
    subdominio.
  - El regreso de un pago externo lo admite, porque la sesión del cliente vive en ese
    dominio.
- **Certificados.** La API y el programador escriben la configuración dinámica de
  Traefik: un router por dominio que se sirve, con su certificado por HTTP-01. Va en
  un directorio que Traefik vigila (`docker-compose.dominios-propios.yml`). Los
  dominios ya pasaron la normalización: no pueden inyectar nada en el YAML.
- **Bitácora**: `sitio.dominio_conectado` y `sitio.dominio_quitado`.

## Consecuencias

- Encenderlo en producción toca el Traefik compartido: un resolvedor HTTP-01, el
  proveedor `file` y `sitios.{dominio}` sin el proxy de Cloudflare. Requiere
  autorización (docs/DESPLIEGUE.md → «Dominios propios»).
- Con `docker-compose.cloudflare.yml` (solo entra Cloudflare), los dominios de los
  negocios no llegan. Habría que usar Cloudflare for SaaS, que es otra decisión.
- La sesión es por dominio: quien entra en el dominio propio no queda dentro en el
  subdominio, y al revés. Los correos siguen enlazando al subdominio, que siempre
  funciona.
- Sin validar en producción: probado con DNS simulado, no con un dominio y un
  certificado reales.
