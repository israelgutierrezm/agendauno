# ADR 0155 — La página de cada negocio: contenido indexable, datos estructurados y 404 reales

Estado: Aceptado (2026-10-17). Continúa el ADR 0128 (cabecera de la página de cada
negocio).

## Contexto

Vendemos «tu propia página que atrae clientes». Las landings de las marcas se
pre-generan, pero la página de cada negocio (`{slug}.agendauno.mx`) es la SPA:

- su cuerpo llegaba vacío a quien no ejecuta JavaScript;
- sus datos estructurados eran un `LocalBusiness` genérico, con la dirección como
  «Ciudad, MX»;
- todo respondía 200, aunque el negocio no existiera o no estuviera publicado (soft
  404);
- el dominio principal no listaba los negocios.

## Decisión

- **Datos públicos con caché** (`DatosPublicosNegocio`): los del escaparate con el sitio
  publicado, guardados 10 minutos por negocio. nginx los pide en cada página servida;
  así no se abre la base del negocio cada vez. La web siempre lee lo de ese momento.
  - Con esto, la cabecera abre la base del negocio, cosa que el ADR 0128 evitaba, pero
    con caché y solo en Inicio. Si falla, salen los datos estructurados básicos.
- **Contenido indexable**:
  - `app.html` trae, dentro de `#app`, un include SSI a `/_cuerpo-negocio`.
  - `GET /pagina-negocio/cuerpo` responde HTML con texto escapado:
    - lo que escribió en su sitio (títulos, textos, preguntas, beneficios, testimonios);
    - sus servicios o clases con precio;
    - las próximas clases;
    - su equipo;
    - sus sucursales con dirección, teléfono y horario.
  - Se ve un instante y la web lo reemplaza al montarse.
  - En el dominio principal no hay SSI y queda como comentario.
- **Datos estructurados completos** (`SeoPaginaNegocio::datosEstructurados`):
  - El tipo de schema.org sale del giro.
  - Con una sucursal: `PostalAddress`, `telephone` y `openingHoursSpecification`. Con
    varias, cada una como `department`.
  - El escaparate da los mismos a la web (`json_ld`), para que la página y su cabecera
    no se contradigan.
- **404 reales**:
  - En el subdominio, nginx pregunta con `auth_request` a `GET /pagina-negocio/existe`.
  - Responde 403 en estos casos, y nginx sirve la misma aplicación con estado 404:
    - un subdominio sin negocio;
    - la página pública de uno sin publicar;
    - una página propia que no existe.
  - Lo demás existe: entrar, su panel, otro host.
  - Si la API falla o no responde, se sirve como siempre. Una comprobación nunca tumba
    una página.
- **Sitemaps**:
  - `/sitemap-negocios.xml` en el dominio de cada marca (de la API) lista los sitemaps
    de sus negocios del directorio. El robots de la landing lo anuncia.
  - Cada subdominio sigue anunciando el suyo.
- **Dominio propio**: no se hace aquí. El diseño está en `docs/MULTIPRODUCTO.md`.

## Consecuencias

- Cada página del subdominio hace hasta tres subpeticiones a la API (existe, cabeza,
  cuerpo), con el límite `pagina-negocio`. Si se excede, la página se sirve igual.
- Lo que un buscador ve puede tardar hasta 10 minutos en reflejar un cambio del
  catálogo.
- `ci.yml` comprueba sin API que nada del SSI quede en el HTML y que las subpeticiones
  sean internas. `PaginaNegocioSeoTest` prueba el contenido, los datos estructurados,
  la existencia y el índice.
- Que los buscadores acepten los sitemaps de los subdominios desde el dominio
  principal pide verificar el dominio completo en su consola.
