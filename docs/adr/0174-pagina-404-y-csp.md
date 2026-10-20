# ADR 0174 — Página 404 y Content-Security-Policy

Estado: Aceptado (2026-10-20).

## Contexto

- Una dirección que no existía (de dos segmentos o más; las de uno las atiende la página
  del negocio) mandaba al inicio sin avisar. Quien seguía un enlace roto no se enteraba
  de que la página no existe, y un buscador veía la portada en esa dirección.
- La única CSP era `frame-ancestors`. Nada limitaba de dónde se cargan scripts, estilos,
  conexiones o marcos.

## Decisión

### Página 404

- El comodín del router muestra «No encontramos esta página»
  (`NoEncontradaView`), dentro del marco público.
  - Lleva al inicio («Ir a mi inicio» si hay sesión).
  - Si se llegó desde otra página, ofrece volver.
  - No se indexa.
- En el subdominio de un negocio, nginx ya la entrega con estado 404 (ADR 0155). En el
  dominio principal llega con 200 y `noindex`: nginx no conoce las rutas de la
  aplicación.

### Content-Security-Policy

nginx manda la CSP completa en todas las respuestas (`$csp_base` más el
`frame-ancestors` de cada ruta). Solo permite lo que la plataforma usa:

| Directiva | Qué deja |
|---|---|
| `script-src` | La aplicación, GA4 (gtag) y Meta, el botón de Google, reCAPTCHA y OpenPay. Sin JavaScript en línea ni `eval`: la aplicación y la landing no lo usan. |
| `style-src` | La aplicación, estilos en línea (la landing y los componentes los ponen), Google Fonts y el botón de Google. |
| `img-src`, `media-src` | Cualquier `https:`, `data:` y `blob:`: las imágenes de los negocios pueden estar en S3 o un CDN (ADR 0170). |
| `connect-src` | La propia API, GA4, Meta, Google y OpenPay. `CSP_CONECTAR_EXTRA` (web.env) suma orígenes, p. ej. un `VITE_ANALYTICS_ENDPOINT` en otro dominio. |
| `frame-src` | La propia plataforma (vista previa, widgets), YouTube y Vimeo (videos del sitio), Google y reCAPTCHA, OpenPay. |
| Además | `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `worker-src` y `manifest-src` propios. |

- **Lo que la CSP bloquee, la web lo reporta** al monitoreo de errores con el tipo
  `CSP` y lo que bloqueó (sin la query). Para una página de la plataforma eso es un
  servicio que falta en la lista, no un ataque. Lo que inyectan las extensiones del
  navegador no se reporta.
- La CI comprueba la cabecera en las páginas: sin `unsafe-` en `script-src` y con el
  `frame-ancestors` de cada ruta.

## Consecuencias

- Un servicio externo nuevo (otro proveedor de medición, un mapa embebido, otra
  pasarela con script propio) tiene que entrar en la CSP de `nginx.conf.template`. Si
  no, lo bloquea y aparece en Errores.
- OpenPay (su script de tarjeta y la huella del dispositivo) solo se probó con
  simulaciones. Si su huella carga de otro dominio, aparecerá en Errores como CSP y
  habrá que sumarlo.
