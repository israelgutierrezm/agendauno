# ADR 0167 — Sitio web en Captación, con pestañas y «Plus»

Estado: Aceptado (2026-10-19). Reacomoda lo existente; no agrega un sistema aparte.

## Contexto

El sitio del negocio vivía en Configuración › Sitio web, con dos opciones: el
constructor (`/sitio`) y la «Página pública» (`/configuracion#pagina-publica`). Lo de
compartirlo estaba repartido entre Captación, Inicio y el asistente. Configurar el
negocio (cómo opera) y preparar su página (cómo se presenta para conseguir clientes)
son tareas distintas.

En «Página pública», el interruptor «Aparecer en el directorio» decía que la página
seguía disponible con el enlace, pero en realidad la cerraba. Dejarla «solo con el
enlace» solo se podía elegir en el asistente.

## Decisión

### Menú

Captación es un grupo propio, entre Gestión y Configuración, con tres entradas:

- **Prospectos**, con sus formularios de captación;
- **Sitio web**, entrada directa;
- **Campañas**, el reporte por campaña (ADR 0144) con su periodo.

El resto del menú no cambia. «Sitio web» sale de Configuración.

Las direcciones anteriores redirigen:

| Antes | Ahora |
|---|---|
| `/configuracion#pagina-publica` | Sitio web › Contenido |
| `/captacion/integrar` | Sitio web › Compartir |

### Sitio web: pestañas y barra fija

Arriba, siempre a la vista:

- si hay borrador sin guardar, sin publicar o está publicado;
- Vista previa;
- Ver sitio, si la página está abierta;
- Descartar;
- Guardar borrador;
- Publicar cambios.

Guardar no publica: es el mismo borrador de siempre (ADR 0114).

| Pestaña | Contiene |
|---|---|
| Contenido | El perfil público (portada, descripción, redes y color de la app) y el constructor: plantilla, estilo, páginas, secciones, versiones, pie, banners y vista previa. |
| Reservas y ventas | Lo que se muestra de cada módulo: servicios o clases, planes o bonos, horario, equipo y sucursales, con su precio. Se puede mostrar u ocultar su sección (es borrador) y se edita en su módulo. No hay un segundo catálogo. También dice qué puede hacer quien visita: agendar y dejar sus datos. |
| Compartir | El enlace con su QR, la página de enlaces, los formularios de captación y los widgets para otra web (ADR 0141). |
| Dominio y SEO | La dirección pública; cómo se publica (en el directorio, solo con el enlace o cerrada, al momento, con confirmación al cerrar); el SEO de Inicio (borrador); el dominio propio (Plus, aún no disponible). |

Quién entra:

- Quien configura el negocio (`estudio.gestionar`) ve todas las pestañas.
- Quien solo atiende prospectos (`prospectos.ver`) entra únicamente a Compartir: no
  carga ni edita el sitio.

### Base y Plus

- **Base, en todos los planes:**
  - la página lista para compartir;
  - plantilla, secciones del sistema, textos y fotos;
  - banners, pie, SEO de Inicio y versiones;
  - reservas.
- **Plus:** la función de plan `sitio_avanzado`, desde Premium. El superadmin puede
  cambiar el nivel en la tarifa. Incluye:
  - el estilo propio y el botón del encabezado;
  - las páginas propias;
  - los bloques propios;
  - el dominio propio, cuando exista.

  Los negocios de clases tienen todas las funciones (ADR 0107).

El servidor lo exige en `PUT /sitio` y en la vista previa en vivo. Sin Plus:

- lo que ya tenía se conserva y se puede quitar;
- agregarlo o cambiarlo responde 403 `PLAN_FEATURE_NOT_INCLUDED`.

La web lo marca con «Plus» y deshabilita lo que no se puede agregar.

### Leyenda de la plataforma

Toda página pública de un negocio lleva en el pie «Hecho con AgendaUno», o «Hecho con
TurnoUno» según su producto. Aplica a:

- su sitio y sus páginas;
- la vista previa;
- la página de enlaces y las de agendar y sucursales;
- los formularios y los widgets, también dentro de otra web.

No depende de ningún ajuste del negocio: ni el editor ni un plan la quitan.

## Consecuencias

- `GET /sitio` suma `publicacion` {`publicado`, `privado`}.
- La vista previa del sitio se muestra como la página publicada, con su pie y su
  leyenda, no con el encabezado comercial.
- Pendiente: el dominio propio (requiere infraestructura) y, con él, su permiso.

## Fase 2: qué se ve de cada módulo

En «Reservas y ventas», cada servicio o clase, plan o bono y profesional tiene «Se ve».
Lo oculto va en el borrador del sitio:

- se guarda como `ocultos` {`servicios`, `productos`, `equipo`}, una lista de ids
  públicos por grupo;
- se publica con lo demás;
- no se agrega un campo a cada módulo: el sitio decide cómo mostrar, sin un segundo
  catálogo.

Lo oculto sigue a la venta y se agenda igual; solo no aparece en las secciones del
sitio.

En el servidor:

- `EscaparateTenant` lo quita de sus listas y no publica qué está oculto;
- `GET /sitio/oferta` lista todo, para decidir;
- el escaparate suma el `id` de cada profesional.

## Fase 3: permisos del sitio

| Permiso | Qué permite |
|---|---|
| `sitio.editar` | Preparar el sitio: el borrador, la vista previa, las fotos, descartar y volver a una versión |
| `sitio.publicar` | Lo que se ve al momento: publicar, abrir o cerrar la página (`PUT /publicacion`), el perfil público y la portada |

- `sitio.publicar` pide `sitio.editar`.
- Quien colabora prepara cambios y otro los publica.
- El administrador del sistema tiene los dos.
- La migración tenant 000162 da los dos a los roles propios que tenían
  `estudio.gestionar`, para que nadie pierda acceso.
- La entrada «Sitio web» se abre con `sitio.editar` o con `prospectos.ver`; con este
  último, solo la pestaña Compartir.

## Más formas de mostrar lo que ya tiene

Son opciones nuevas sobre lo que ya existe; no cambian la estructura del sitio.

- **Cómo se muestra** (Plus):
  - el equipo: como siempre, en tarjetas, en mosaico o en carrusel;
  - las reseñas: como siempre o en carrusel;
  - en los bloques: la galería en mosaico o en carrusel y los testimonios en carrusel.

  Va en la sección (`presentacion`) o en los datos del bloque (`datos.presentacion`).
  El catálogo del editor dice las opciones de cada tipo, y sin Plus no se cambia.
- **El carrusel:**
  - se desliza con el dedo, el teclado o sus flechas;
  - no se mueve solo;
  - sin animación si quien lo ve prefiere menos movimiento.
- **La ficha de cada integrante**, para todos los planes: al tocar a alguien del equipo
  se abre con su foto y su semblanza completa.
  - En citas: «Agendar con…», que abre la página de agendar con esa persona ya elegida
    (`/agendar/{slug}?profesional={id}`, si atiende en la sede que se elija).
  - En clases: sus próximas clases.

  La foto y la semblanza siguen editándose en el perfil de cada quien.

