# ADR 0206 — Política de cookies y aviso de cookies al entrar

Estado: Aceptado (2026-10-24).

## Contexto

- El aviso «Tu privacidad» (ADR 0143) solo aparecía donde había destinos de medición
  (GA4 o Meta) encendidos. Sin destinos, quien entraba a la landing no veía ningún
  aviso ni sabía qué guarda el sitio en su navegador.
- El aviso de privacidad decía «No usamos cookies de publicidad», y no había un
  documento que dijera qué cookies se usan, para qué y cuánto duran.
- El dueño pidió una política de cookies y un aviso de consentimiento al entrar.

## Decisión

- **La política de cookies es un documento legal más** (`DocumentoLegal::COOKIES`,
  tipo `cookies`), con el mismo ciclo que el aviso y los términos: borrador que edita el
  superadmin en Plataforma → Legales, texto base en `resources/legales/cookies.txt`
  (copia en `apps/web/src/marketing/legales/` para la vista previa de desarrollo) y
  versiones publicadas inmutables. Usa `{responsable}` y `{contacto}`, así que
  publicarla pide los datos del responsable.
- **Es informativa:** no se acepta al registrarse (la constancia de aceptación sigue
  siendo del aviso y los términos). La elección se pide en el aviso de cookies.
- **Se publica en `/cookies`** (sin indexar, como las otras páginas legales), enlazada
  desde el pie de las landings, el pie de la página de cada negocio, el aviso de
  cookies y el aviso de privacidad. En la misma página se cambia la elección de este
  navegador para el sitio en que se abre (el producto o, en el subdominio de un
  negocio, ese negocio).
- **El aviso de cookies sale al entrar** a las páginas del producto aunque todavía no
  haya destinos encendidos, como el de Fitune: una barra oscura y algo translúcida al
  pie, a todo lo ancho, con el título «Esta página web usa cookies», qué datos obtienen
  y para qué, un solo botón («Aceptar») y el enlace «Cookies y configuración», donde se
  elige o se rechaza la analítica y la publicidad. Mientras no se acepte, solo
  funcionan las necesarias. En la página de un negocio solo aparece si ese negocio mide
  (sin destinos no hay nada opcional que autorizar).
- **«Cookies y configuración» queda en el pie** de las páginas públicas y vuelve a abrir
  la barra con lo elegido (no hay botón flotante).
- **Por qué basta con «Aceptar»:** la LFPDPPP y los lineamientos del aviso de privacidad
  piden avisar, al entrar, que se usan cookies, qué datos obtienen, para qué y cómo
  deshabilitarlas; no piden un botón de rechazo en la barra. Aquí además nada opcional
  se carga sin aceptar (más estricto que el consentimiento tácito que admite la ley) y
  rechazar está a un clic en «Cookies y configuración», en la barra, en el pie y en
  `/cookies`.
- **La verificación de producción** exige la política publicada, como el aviso y los
  términos.

## Consecuencias

- Lo que ya existía no cambia: sin elegir nada no se carga GA4 ni Meta, cada producto y
  cada negocio guardan su propia elección y retirar el permiso borra las cookies.
- Antes de abrir hay que publicar la política (y una versión nueva del aviso de
  privacidad, cuyo texto base ya remite a ella) desde Plataforma → Legales.
- Si se agrega una herramienta opcional o cambia para qué sirve una, se publica una
  versión nueva de la política y se sube `VERSION_PREFERENCIAS` para volver a pedir la
  elección.
