# ADR 0114 — El sitio de cada negocio: plantillas, secciones, banners y publicar

Estado: Aceptado (2026-10-10). Parte del ADR 0108 (plataforma multiproducto).

## Contexto

Cada negocio (de AgendaUno o de TurnoUno) ya tenía su página pública (el escaparate en
su subdominio): portada, descripción, redes, agenda o próximas clases, servicios,
horario, precios, equipo, reseñas y sedes, siempre en el mismo orden. El negocio debía
poder darle su forma sin un editor libre tipo Webflow: elegir una plantilla, mostrar,
ocultar y reordenar secciones, editar textos y fotos, administrar banners, ver una vista
previa y publicar. La información operativa (horarios, precios, servicios) debe seguir
saliendo del sistema, nunca copiarse al sitio.

## Decisión

- **Configuración, no contenido operativo.** El sitio es una configuración por negocio
  con forma fija y validada (no EAV): `plantilla`, `secciones` (tipo, visible, título,
  texto, foto) y `banners` (título, texto, botón con enlace, fechas, foto). Cada sección
  dice si aparece, dónde y con qué título y texto; lo que muestra sale del sistema en
  cada visita.
- **Secciones** (`CatalogoSitioWeb`): `inicio` (portada) siempre primero y `contacto`
  (cómo escribir, reservar y el aviso de privacidad) siempre al final; las demás se
  mueven y se ocultan: promociones (los banners), nosotros (texto y foto propios),
  agenda, servicios, horario (solo clases, ADR 0104), precios, equipo, reseñas y
  sucursales. Texto propio: portada, nosotros y contacto; foto propia: nosotros y
  banners.
- **Plantillas** (`PlantillaSitio`): `esencial` (la página de siempre), `portada` (la
  foto de portada a todo lo ancho con el nombre encima; sin foto, se ve como la
  esencial) y `compacta` (encabezado corto y directo a reservar). Cada una propone un
  orden; cambiar de plantilla conserva textos, fotos, visibilidad y banners.
- **Borrador y versiones.** En la base del negocio: `sitio_web` (el borrador, una fila)
  y `publicaciones_sitio_web` (cada versión publicada; la última es la que ve el
  público). Sin nada publicado, el público ve la plantilla esencial con todo visible
  (la página de siempre): no hubo migración de datos. Guardar no publica; «Publicar»
  crea una versión nueva; «Descartar» vuelve a lo publicado.
- **Vista previa.** `GET /sitio/vista-previa` da los mismos datos que el escaparate con
  el borrador, aunque la página aún no esté abierta al público. La web la muestra en
  `/estudio/{slug}/vista-previa` (mismo origen que el panel, con su sesión) dentro del
  editor, en un marco con ancho de teléfono o de computadora, o en otra pestaña. Como
  nginx prohíbe los marcos en todo lo demás (`X-Frame-Options: DENY`), en esa ruta manda
  `SAMEORIGIN` y `frame-ancestors 'self'`: sin eso, el marco quedaba en blanco en el
  servidor aunque en local funcionara.
- **Banners** vigentes según el día del negocio (su zona horaria); su enlace solo puede
  ser una página `https://`, una ruta del sitio (`/…`) o una sección (`#…`), nunca
  `javascript:` ni otra cosa. Cuántos banners y cuántas fotos admite cada sitio son
  parámetros (`sitio.banners_maximos`, `sitio.imagenes_maximas`), por negocio o de la
  plataforma.
- **Fotos** solo subidas desde el editor (JPG, PNG o WebP de hasta 4 MB, sin SVG) en
  `estudios/{id}/sitio/` del disco público; el sitio no acepta URL ajenas. Al guardar,
  publicar o descartar se borran las que ya no usan el borrador ni las últimas 10
  versiones publicadas.
- **Color de marca** (ADR 0110) en los botones de la página, con el texto que se lee
  encima (blanco o casi negro según el contraste).
- **Permisos.** Todo lo del sitio exige `estudio.gestionar` (dueño y admin); el público
  solo ve lo publicado.

## Consecuencias

- Un cambio de horario o de precio se ve en el sitio en cuanto se hace: el sitio no
  guarda copias.
- La página de cada negocio puede diferir en orden y textos sin otro proyecto ni otra
  plantilla de código; las tres plantillas comparten un solo componente.
- Las versiones publicadas quedan como historia (todavía sin pantalla para volver a
  una anterior).

## Pendiente

- Volver a una versión publicada anterior desde el editor.
- Más plantillas y secciones (galería, preguntas frecuentes, testimonios propios).
- Un editor libre (bloques arbitrarios) queda fuera a propósito.
