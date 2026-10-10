# Landing, captación y SEO

## Páginas y jerarquía

Dos productos, un solo código (ADR 0108 y 0109): **AgendaUno** (clases, agendauno.mx)
y **TurnoUno** (citas, turnouno.mx). Cada dominio publica solo las páginas de su
producto, con su propio build de landing (`npm run build:agendauno`,
`build:turnouno`), su SEO, su sitemap y su robots:

```
agendauno.mx/                  portada de Clases con cupo (ModalidadView, modo clases)
└── /software-para-{pilates, pole-dance, academias, crossfit-hyrox}
turnouno.mx/                   portada de Citas 1 a 1 (ModalidadView, modo citas)
└── /software-para-{barberias, spas, terapeutas, nutriologos}
```

`/clases` en agendauno.mx lleva a `/`; `/citas` y las páginas por giro de citas, a
turnouno.mx (y al revés). Las páginas salen de `paginasMarketing`
(`src/marketing/seoConfig.ts`), la lista única de la que se arman el router de la app
y el del prerender (`src/router/comerciales.ts`, con el mismo nombre, `meta` y props),
el SEO, el sitemap, la analítica y las revisiones de `scripts/check-marketing*.mjs`
(una por producto). `seoConfig.ts`, `soluciones.ts`, `modalidades.ts` y
`lib/producto.ts` los importa `vite.config`: no pueden usar el alias `@/`, vue-i18n ni
el i18n del proyecto (su SEO va en texto plano).

Los textos base están en AgendaUno; en TurnoUno se leen con su marca y su dominio
(`conMarca`). El producto de la página sale del build (`VITE_PRODUCTO`), del host o,
en desarrollo, de `?producto=turnouno`.

- **Portada (`/`, `ModalidadView`, prop `modo` = la modalidad del producto)**: el
  contenido se declara en `src/marketing/modalidades.ts` (`MODALIDADES[modo]`, como
  claves) y los textos van en `landing.clases.*` y `landing.citas.*`. Secciones y
  anclas: hero, sellos, `#producto` (demo fija por modo), `#soluciones` (funciones),
  `#como-funciona`, `#para-quien` (carrusel y páginas por giro de su modalidad),
  `#operacion` (recepción y ventas), `#precios`, `#pagina-publica`, `#preguntas` y
  el llamado final, que cierra con un enlace discreto al otro producto. El menú es
  Funciones · Precios · Preguntas.
- **Prelanzamiento**: mientras un producto no recibe registros (`/precios` →
  `registro`, lo decide el superadmin), sus botones dicen «Quiero que me avisen», el
  cierre «abre pronto» y `/registro` muestra la lista de interesados.
- **Páginas por giro (`/software-para-*`, `SolucionView`)**: miga de pan
  «AgendaUno / Clases|Citas / giro», enlaces a `/{modo}#producto`, `/{modo}#precios`
  y `/registro?modo={modo}` (con `&giro={perfil}` si la página es de un solo giro del
  registro), y los demás giros agrupados en «Clases» y «Citas».
- **Nombres**: la modalidad se llama «Clases con cupo» / «Citas 1 a 1» en la portada,
  los precios y el registro (`NOMBRE_MODALIDAD`, igual a `modalidadNegocio.nombres`;
  una prueba lo vigila). El menú dice «Clases» / «Citas».
- **Textos veraces**: no se anuncia lo que no existe. Nada de «anticipos» (es «cobro en
  línea al agendar*»); recordatorios solo por correo (sin WhatsApp, push ni app); el QR
  es un pase de entrada y solo en gimnasios, CrossFit y HYROX (acceso libre, ADR 0105;
  la asistencia se pasa en lista, con retardos); en clases no hay
  autorregistro de alumnos (la página pública termina en «Pedir acceso / Ya soy
  alumno», ADR 0093); los spas sí manejan cabinas y equipos como recursos. Donde se
  mencionan cobros en línea o facturación va «*» y la nota «* Solo para clientes de
  México» (`.tu-nota-mexico`, ADR 0099).

### SEO de cada página

- Title, description, canonical y Open Graph propios (`og:image` es una foto que ya
  existe en `public/`: Pilates en `/clases`, barbería en `/citas`).
- JSON-LD: `Organization`, `SoftwareApplication` y `WebPage` en las 11. `BreadcrumbList`
  en `/clases` y `/citas` (Inicio → Clases|Citas) y en cada página por giro
  (Inicio → Clases|Citas → giro). Sin `Offer`, `FAQPage` ni `hreflang`.
- El sitemap lista las 11. Registro, acceso, activación, directorio, aviso de
  privacidad y la aplicación quedan con `noindex,follow`, sin canonical ni JSON-LD.

## Menú comercial

«Clases · Citas · Precios · Entrar · Probar gratis». `PublicShell` decide si una página
es comercial por `route.meta.marketing` (no por el nombre de la ruta), igual en la app
y en el prerender: el HTML sin JS y la app montada muestran el mismo menú.

- Desde 1100 px va en la barra; en pantallas más angostas, en una segunda fila bajo la
  barra (solo CSS, sin JS). `aria-current` marca la modalidad activa.
- «Precios» lleva a `#precios` de la misma página en `/clases` y `/citas`, y a
  `/#precios` en las demás.
- «Probar gratis» lleva a `/registro?modo={modo}` cuando la página tiene modalidad
  (`meta.modo`: `/clases`, `/citas` y las páginas por giro) y a `/registro` en la
  portada. En una página por giro de un solo giro también lleva `&giro={perfil}`
  (`meta.giro`, de `paginasMarketing`), igual que su hero y su cierre.

## Negocios en su subdominio

Los negocios viven solo en `{slug}.agendauno.mx`; el dominio principal no tiene rutas
con el slug de un negocio (`urlEnSubdominioDelNegocio` en `src/lib/tenant.ts`, guarda
del router):

| En `agendauno.mx` o `www.agendauno.mx` (producción) | Va a                                       |
| ---------------------------------------------------- | ------------------------------------------ |
| `/{slug}` (enlace corto)                             | `https://{slug}.agendauno.mx/`             |
| `/estudio/{slug}` (página del negocio)               | `https://{slug}.agendauno.mx/estudio/{slug}` |
| `/{slug}/enlaces` (enlaces para Instagram)           | `https://{slug}.agendauno.mx/enlaces`      |

- Se conservan la query y el ancla (`/estudio/foo?x=1#precios` →
  `https://foo.agendauno.mx/estudio/foo?x=1#precios`). La página del negocio conserva
  `/estudio/{slug}`: si fuera a la raíz, un negocio de citas con una sede iría directo
  a agendar y se perdería el ancla.
- `/agendar/{slug}` no cambia: lo generan el API, los correos y los regresos de pago.
- En desarrollo (`localhost`) todo sigue en la app, como antes. Un slug que no sirve
  como subdominio (o reservado: `www`, `api`, `app`…) no se redirige.
- En el subdominio de un negocio, ninguna página comercial (`meta.marketing`: la raíz,
  `/clases`, `/citas`, `/software-para-*`) muestra la landing de AgendaUno: van a
  `sucursales-estudio`, la entrada pública del negocio.
- **Antes de publicar**: `/clases` y `/citas` tapan el enlace corto de un negocio con
  ese slug en el dominio principal (los slugs no se reservan; en su subdominio sigue
  funcionando). Revisar `SELECT slug FROM estudios WHERE slug IN ('clases','citas');`.

## Registro con modalidad

`/registro?modo=clases|citas&giro=<perfil>` llega a `RegistroView` como props de ruta
(`modoDeQuery` y `giroDeQuery`: sin espacios, en minúsculas; lo inválido se ignora).

- Con `?modo=`, solo se ven los giros de esa modalidad y, debajo, un enlace discreto
  («¿Das clases? Ver giros de clases» / «¿Atiendes con cita? Ver giros de citas») que
  muestra los de la otra sin perder lo escrito. Sin modo, los dos grupos.
- Con `?giro=` (un giro del registro), el tipo de negocio ya viene elegido: en lugar del
  selector, «Tipo de negocio: Barbería · Citas 1 a 1» con «Cambiar». Si el giro es de
  otra modalidad que `?modo=`, manda el giro.
- Mandan el giro las páginas por giro de un solo giro, en todos sus «Probar gratis»
  (hero, cierre y menú; `PERFIL_DE_SOLUCION` en `src/marketing/modalidades.ts`;
  «Barberías y estéticas» junta dos y solo lleva `?modo=`) y el botón del carrusel de
  `/clases` y `/citas` cuando la persona eligió un negocio de un solo giro
  (`PERFIL_DE_NEGOCIO`; mientras gira solo, solo `?modo=`). Elegir un negocio del
  carrusel (clic, toque, deslizar, flechas o teclado) detiene el giro automático, para
  que el negocio y el `?giro=` del botón no cambien mientras la persona baja al botón;
  «Reanudar» lo vuelve a mover.
- El paso 3 resume «Tipo de negocio · Modalidad» («Revísalo antes de crear tu
  negocio.»). Cada modalidad tiene su giro para quien no encuentra el suyo: «Otro
  negocio con clases» (`general`) y «Otro negocio de citas» (`general_citas`). Esos dos
  nombres son del registro: la página pública del negocio y las tarjetas del directorio
  no los muestran como insignia (`perfilVisibleAlPublico`). El filtro del directorio
  ofrece todos los giros del registro, agrupados por modalidad (`PERFILES_POR_MODO`).
- Que solo AgendaUno cambia la modalidad, y solo antes de que el negocio empiece a
  operar (ADR 0104), ya no va en el registro (ni en la ayuda del tipo de negocio ni en
  el resumen del paso 3): es una pregunta frecuente de la portada, `/clases` y
  `/citas`.

Botones de las páginas comerciales: los del hero (portada, `/clases`, `/citas` y
páginas por giro) van en azul (`tu-btn-azul`: `--primario`, con `--primario-contraste`
y `--primario-fuerte` al pasar el cursor); las tarjetas de precio llevan el borde de
arriba rosa (`--marketing-cta`) y su botón azul. «Probar gratis» del menú y los
llamados finales siguen en rosa.

## Cambios y comprobación

- Paleta pública aislada del panel operativo: CTA azul tinta `#223B4B`, énfasis grande verde Bootstrap `#198754` y éxito en texto pequeño `#146C43` por contraste. Fondos `#F6F8FC` y superficies `#FFFFFF` / `#E9EDF5`; en oscuro, `#020D24` y superficies `#071A3A` / `#0B2854`. En oscuro, CTA claro `#DCE5E5` y énfasis `#75B798`. El progreso conserva el azul petróleo `#24566B` (`#376D82` en oscuro). No se modifica ningún logo.
- `src/marketing/public-ui.css`, cargado desde `main.ts`, comparte tipografía, controles de 44 px como mínimo, radios y espaciados solo en el marco público. `src/marketing/landing.css` (títulos, hero, tarjetas, pasos y la aparición al desplazarse, bajo `:where(.tu-landing)`) lo comparten la portada y `ModalidadView`. Las páginas operativas no reciben estas reglas. El header conserva logo, posición fija y tema; las rutas de tarea no repiten el CTA de registro ni el menú comercial.
- Las secciones alternan el fondo del hero y la superficie, sin bloques consecutivos del mismo fondo. Un solo h1 por página; títulos de tarjeta con peso 500; estados como punto + texto; marcas de verificación y flechas en SVG, sin íconos teñidos ni emojis. Acceso mantiene recientes y búsquedas, pero sin duplicar el logo ni mostrar resultados vacíos antes de una búsqueda explícita.
- `/aviso-de-privacidad` está enlazado desde el footer y antes de los campos de registro. Comparte el documento editable de `/api/v1/legales` con el modal de registro. El borrador `src/marketing/aviso-privacidad.borrador.txt` solo se muestra en desarrollo si no existe documento publicado. No debe publicarse sin completar los datos del responsable y validar prácticas, proveedores y controles. La ruta legal queda fuera del sitemap y sin indexación; no altera el SEO comercial.
- Cada página por giro tiene contenido propio, fotos existentes, CTA al registro con su modalidad, título, descripción y canonical. No se inventan testimonios, calificaciones ni precios.
- `npm run build` genera la aplicación (`dist/app`) y el HTML completo de las páginas de cada producto (`dist/agendauno`, `dist/turnouno`) reutilizando los componentes Vue de marketing (`src/entry-marketing.ts`, con su propio router en memoria). La aplicación de gestión sigue siendo SPA; no se consultan cuentas ni datos de negocios durante la compilación. Las demos (agenda, página pública) son estáticas, sin API ni stores; `window`, `matchMedia` y `localStorage` solo se usan en `onMounted`.
- Validar con `npm run lint`, `npm run typecheck`, `npm test`, `npm run build` y `npm run test:marketing`. La CI de la web corre lint, build, `test:marketing` y test. `test:marketing` revisa en cada página: un h1 con texto, más de 1,000 caracteres sin JS, canonical, Open Graph, JSON-LD (con su miga de pan), el menú, `#precios`, el registro con `?modo=` (y `&giro=` en las páginas de un solo giro, también en el «Probar gratis» del menú), imágenes con alt y archivo, el CSS de su vista enlazado y el sitemap; y por HTTP, que `/clases` en el host de un negocio quede `noindex`. `npm run preview` sirve las rutas generadas y el fallback de acceso.
- Las animaciones no ocultan el texto cuando JavaScript no está disponible. Se respeta movimiento reducido.

## Configuración necesaria al publicar (no se despliega desde este cambio)

Publicar `dist`, nunca `dist-ssr`. El servidor debe distinguir dominio comercial y subdominios de negocios:

1. En `agendauno.mx` y `www.agendauno.mx`, servir `/` desde `dist/index.html` y cada página comercial (`/clases`, `/citas`, `/software-para-*`) desde su propio `index.html` (`try_files $uri/index.html $uri /app.html` en `infra/produccion/nginx.conf.template`).
2. Servir archivos reales, CSS, JS, imágenes, robots y sitemap normalmente. Un asset inexistente debe responder 404, no HTML.
3. Acceso, registro, activación, pantallas privadas y rutas dinámicas usan `dist/app.html` como fallback. **No usar el HTML de la landing como fallback universal.** Las rutas estáticas de registro/acceso también tienen HTML propio sin indexación. El enlace corto y la página de un negocio en el dominio principal cargan `app.html`, que los manda a su subdominio.
4. En `{negocio}.agendauno.mx` y hosts de aplicación, servir `app.html` para rutas de aplicación, incluida `/`; nunca la landing comercial. Mantener la resolución de tenant existente.
5. Redirigir HTTP a HTTPS y www al dominio canónico en producción. Comprobar códigos HTTP y encabezados antes de enviar el sitemap. El `noindex` no sustituye autenticación.

El directorio queda como herramienta de acceso, fuera del sitemap comercial. Los escaparates dinámicos conservan sus metadatos al cargar, pero no se prerenderizan con datos de negocios; si se quiere posicionar cada negocio, requiere un trabajo separado de renderizado y respuestas HTTP 404 reales. El catch-all histórico de Vue también debe revisarse antes de una estrategia SEO de escaparates.

## Search Console

No hay una propiedad conectada automáticamente. Si se utiliza verificación por etiqueta HTML, colocar el **token público** en `GOOGLE_SITE_VERIFICATION` y recompilar. Para una propiedad de dominio, verificar por DNS desde el proveedor correspondiente. Publicar y enviar `https://agendauno.mx/sitemap.xml`; inspeccionar la portada, `/clases`, `/citas` y una página por giro. La configuración técnica facilita indexar, no garantiza posiciones.

## Embudo y medición

La integración existente emite eventos a `window.dataLayer`, al evento local `agendauno:analytics` y, si se configura, a `VITE_ANALYTICS_ENDPOINT`. Sin un receptor o un gestor de etiquetas configurado no existe un panel de métricas ni almacenamiento persistente. No se instala GA4 ni se envían datos a una cuenta externa nueva.

La modalidad viaja siempre como `mode`, con solo dos valores: `clases` o `citas` (nunca `modo`).

| Etapa             | Evento                                  | Propiedades                                                                                                  |
| ----------------- | --------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Visita comercial  | `page_view`                             | Navegación completada a una página comercial o al registro                                                   |
| Elección          | `marketing_business_mode_selected`      | `mode`, `placement`: `hero`, `business_modes`, `business_types` (portada) o `mode_page_footer` (la otra modalidad) |
| Interés           | `marketing_cta_clicked`                 | `placement`, `destination` (`register`, `pricing`, `product`), `mode` cuando la página tiene modalidad, `solution` en las páginas por giro y `business_profile` (la clave del giro) cuando el enlace lleva `?giro=` |
| Inicio            | `studio_registration_started`           | `mode_intent` si llegó con `?modo=` o `?giro=` (la modalidad del giro manda) y `business_profile_intent` si llegó con `?giro=` |
| Avance            | `studio_registration_step_completed`    | `step`, `mode_intent`, `business_profile_intent`                                                             |
| Error             | `studio_registration_failed`            | `step`, `mode_intent`, `business_profile_intent`                                                             |
| Registro          | `tenant_created`                        | `business_profile`, `mode` (la modalidad del giro elegido), `mode_intent`, `business_profile_intent`         |
| Activación        | `tenant_activated`                      | API confirma activación                                                                                      |
| Pago              | Pendiente de conectar del lado servidor | Confirmación real de la pasarela, no clic ni retorno del navegador                                          |

Los `placement` de `marketing_cta_clicked`: `navigation` («Probar gratis» del menú), `hero`, `product_demo`, `features` («Probar en mi negocio»), `business_carousel`, `operations`, `pricing`, `pricing_card`, `public_page`, `final`, `solution_hero` y `solution_final`. Comparar `mode_intent` con `mode` de `tenant_created` muestra quién llegó por una modalidad y registró otra.

Las rutas de activación y operación se agrupan, sin slugs, IDs ni parámetros de URL en `page_path`. No incluir emails, teléfonos, nombres ni datos sensibles en eventos o campañas UTM. El endpoint debe aplicar validación, límites, retención y los controles de privacidad correspondientes; revisar consentimiento antes de activar un proveedor externo.

Para completar el embudo hasta pago: instrumentar la confirmación de suscripción del backend con deduplicación por ID de transacción y primera suscripción pagada por negocio. Usar identificadores internos en un almacén autorizado; no enviarlos a terceros sin revisión. Las cuentas únicas y cohortes requieren esa integración: no calcularlas contando clics. Separar pagos de la suscripción SaaS de cobros que cada negocio recibe de sus propios clientes.

## Validación comercial posterior

Comparar conversión a registro y activación por modalidad (`mode`), página de origen y campaña. Probar una sola variante de propuesta o CTA a la vez cuando haya tráfico suficiente. No atribuir aumentos de conversión al color por sí solo.
