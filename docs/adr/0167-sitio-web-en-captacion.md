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
- Pendiente para otra fase:
  - un interruptor «visible en el sitio» por servicio, plan y profesional;
  - permisos separados para editar, publicar y administrar el dominio;
  - el dominio propio, que requiere infraestructura.
