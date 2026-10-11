# ADR 0128 — La página de cada negocio llega con su cabecera (SSI), su sitemap y su robots

Estado: Aceptado (2026-10-11). El dueño eligió la opción a: nginx le pide a la API la
cabecera del negocio. Cierra el pendiente de la fila 16 de
`docs/ANALISIS-COMPETENCIA-CLASES.md` (M11 y M12).

## Contexto

La aplicación es una SPA: todas sus rutas se sirven con el mismo `app.html`, que trae
una cabecera genérica y `noindex`. La página de cada negocio pone su título, su
descripción, su imagen y su canonical con JavaScript (`updateSeo`).

Quien no ejecuta JavaScript veía solo la genérica. Eso incluye las vistas previas al
compartir un enlace por WhatsApp, Facebook o X, y muchos buscadores. Un negocio que
compartía `{slug}.agendauno.mx` mostraba «AgendaUno» en lugar de su nombre y su foto.
Además, su subdominio no tenía sitemap: el robots era el de la aplicación, sin mapa.

Se consideraron tres opciones:
- **(a)** nginx pide la cabecera a la API con SSI;
- **(b)** prerenderizar cada negocio al publicarse;
- **(c)** renderizar en servidor.

La (b) y la (c) agregan un proceso o un servicio por mantener; la (a) usa lo que ya está.

## Decisión

**La cabecera.** En el subdominio de un negocio, nginx procesa `app.html` con SSI.
1. `scripts/build-app.mjs` envuelve la cabecera genérica en un bloque SSI
   (`<!--# block name="cabeza" -->`) y agrega
   `<!--# include virtual="/_cabeza-negocio" stub="cabeza" -->`.
2. La subpetición interna va a `GET /api/v1/pagina-negocio/cabeza` con
   `X-Pagina: $request_uri` (la dirección que pidió el navegador).
3. Si la API responde vacío (204), con un error o no responde, nginx deja la genérica.
   Un cuerpo de error nunca entra al `<head>`: `fastcgi_intercept_errors` lo convierte
   en 204.

Sin SSI (el dominio principal, desarrollo, la vista previa local), esos comentarios no
hacen nada y la página sigue igual que antes.

`infra/produccion/nginx-api-interna.conf` arma la petición a la API con una ruta fija
(`$api_ruta`). No usa `fastcgi_params`, porque en una subpetición SSI `REQUEST_URI` es
la dirección del navegador y Laravel enrutaría esa. La espera máxima es de 5 segundos.

**Qué páginas.** Las mismas que la web marca en sus vistas, con los mismos textos y las
mismas etiquetas (`renderSeoHead`), para que `updateSeo` las reemplace y no las
duplique:

| Página | Dirección | Canonical |
| --- | --- | --- |
| Inicio | `/`, `/{slug}`, `/estudio/{slug}` | `/` |
| Enlaces | `/enlaces`, `/{slug}/enlaces` | `/enlaces` |
| Sucursales | `/sucursales/{slug}` | `/sucursales/{slug}` |
| Agendar (solo citas) | `/agendar/{slug}` | `/agendar/{slug}` |

- El inicio lleva los datos estructurados `LocalBusiness`.
- Una imagen guardada como ruta del sitio se vuelve absoluta. Sin portada ni logo, va la
  imagen de la marca.
- Cualquier otra dirección (el panel, entrar, la vista previa del sitio) responde 204.

**Qué se indexa.**
- La página solo se ve con la página pública abierta (`paginaPublica()`) y en el
  dominio del producto del negocio.
- Solo se indexa si aparece en el directorio (`enDirectorio()`).
- «Solo con enlace» (`privado`) muestra su vista previa al compartir, pero lleva
  `noindex` y no tiene canonical ni sitemap.
- La web hace lo mismo: el escaparate y las opciones de citas traen `en_directorio`, y
  las vistas pasan `index` a `updateSeo`.

**Sitemap y robots.**
- En el subdominio, `/sitemap.xml` y `/robots.txt` los da la API
  (`/api/v1/pagina-negocio/sitemap.xml` y `/robots.txt`).
- El sitemap lista el inicio y los enlaces, y para citas también agendar, con la fecha
  del último cambio del negocio. Si el negocio no se indexa, responde 404.
- El robots deja rastrear todo: lo que no es página pública ya dice `noindex`. Agrega
  el sitemap si lo hay. Sin API, nginx responde el mismo robots de antes.

**Costo.** La API solo lee el negocio del control plane: no abre su base de datos ni
pasa por `estudio.resolver`. Cada carga de HTML en un subdominio hace esa consulta; la
navegación dentro de la SPA no recarga el HTML. Límite propio de 240 por minuto por IP.

## Consecuencias

- Compartir el enlace de un negocio muestra su nombre, su descripción y su foto.
- Los buscadores reciben su título, su canonical y su sitemap sin ejecutar
  JavaScript.
- El cuerpo de la página sigue siendo la SPA. Lo que la página muestra (clases,
  servicios, precios) lo ve quien ejecuta JavaScript.
- Si la API cae, las páginas se sirven igual, con la cabecera genérica.
- El CI comprueba sin API que la página se sirve con la genérica, sin restos de SSI ni
  mensajes de error, que el robots de respaldo responde y que la subpetición no se abre
  desde fuera.
