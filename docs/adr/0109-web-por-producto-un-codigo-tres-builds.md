# ADR 0109 — Web por producto: un código, tres builds

Estado: Aceptado (2026-10-10). Parte del ADR 0108 (plataforma multiproducto).

## Contexto

La web (`apps/web`, Vue 3) era una sola aplicación con una landing que obligaba a elegir
entre clases y citas (`/`, `/clases`, `/citas`) y un solo dominio. Con dos productos
(AgendaUno en agendauno.mx, TurnoUno en turnouno.mx) cada dominio debe mostrar solo su
propuesta, con su SEO, sin contenido duplicado, y poder publicarse sin rehacer lo
demás. Duplicar el código en tres aplicaciones (landing de cada producto y consola)
multiplicaría el mantenimiento. El dueño eligió **un solo código con tres builds**.

## Decisión

### Tres builds del mismo código

- **`dist/app`** (`npm run build:app`): la aplicación (escaparate y portal de cada
  negocio, registro, acceso, panel, superadmin). Una sola para los dos productos: se
  presenta con la marca del **dominio** en que se abre (`productoActual()`).
- **`dist/agendauno`** y **`dist/turnouno`** (`npm run build:agendauno`,
  `build:turnouno`): la landing pre-generada de cada producto (`VITE_PRODUCTO`), con
  su portada, sus páginas por giro, su `sitemap.xml` y su `robots.txt`, y sus
  recursos en `/assets-{producto}/` para no chocar con los de la aplicación.
- `npm run build` hace los tres. Mismo dominio y mismo origen que hoy: la sesión
  (por origen) sigue funcionando; nginx decide qué build sirve cada ruta.

### Qué sirve cada host (nginx y `vite preview`)

- `agendauno.mx` / `turnouno.mx`: su landing en `/`, `/software-para-*`,
  `/sitemap.xml`, `/robots.txt` y `/assets-{producto}/`; todo lo demás (registro,
  entrar, panel, aviso de privacidad…) es la aplicación (`app.html`).
- `www.` redirige al dominio. `{slug}.agendauno.mx` y `{slug}.turnouno.mx`: siempre la
  aplicación.
- Hoy los tres builds viajan en la misma imagen web; separarlos en imágenes propias
  (para publicar una landing sin la aplicación) es un cambio de infraestructura, no de
  código (ADR 0110).

### Producto en la web

- `lib/producto.ts`: el producto de la página sale del build (`VITE_PRODUCTO`), del
  host, del negocio en sesión (desarrollo) o de `?producto=` (desarrollo, se recuerda
  en la pestaña); si no, AgendaUno.
- La portada de cada dominio es la página de la modalidad de su producto (la de
  `/clases` o `/citas`); la portada genérica que obligaba a elegir se quitó. `/clases`
  en agendauno.mx lleva a `/` y `/citas` (y las páginas por giro de citas) a
  turnouno.mx; al revés en turnouno.mx.
- Los textos base están en AgendaUno: con TurnoUno, «AgendaUno» y «agendauno.mx» se
  leen con su marca y su dominio (`conMarca`, sobre i18n, SEO y los contenidos por
  giro). El cierre de cada landing presenta, discreto, al otro producto.
- TurnoUno aún no tiene logotipo definitivo: su marca se muestra en texto
  (`LogoProducto`, nombre configurable con `VITE_TURNOUNO_NOMBRE`).
- `/precios` dice qué producto recibe registros. Con el registro cerrado (TurnoUno
  antes de su lanzamiento), los botones dicen «Quiero que me avisen» y `/registro`
  muestra la lista de interesados (`ListaInteresados`). El registro abierto solo
  ofrece los giros de su producto y manda el producto.
- El directorio lista los negocios del producto; el superadmin ve a los interesados.

## Consecuencias

- Cada landing se revisa por separado (`scripts/check-marketing.mjs {producto}`: HTML
  sin JS, SEO con su dominio, sin la marca del otro producto salvo el enlace que lo
  presenta) y por HTTP con las reglas de nginx (`check-marketing-http.mjs`).
- `web.env` lleva `DOMINIO` y `DOMINIO_TURNOUNO`; la imagen web sirve los dos.
- Lo que la aplicación muestre a un negocio (correos, enlaces) ya sale del producto del
  negocio en la API (ADR 0108).
