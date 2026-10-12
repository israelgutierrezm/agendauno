# ADR 0146 — Creador de páginas del sitio del negocio

Estado: Aceptado (2026-10-12). Cambia una decisión del ADR 0114 («un editor libre queda
fuera a propósito»). Pedido: «hace falta mejorar mucho ese apartado para que realmente
sea un creador de páginas».

El dueño eligió:

- **bloques prediseñados y varias páginas**, no un editor libre tipo Wix (elementos en
  cualquier posición, que se rompe en celular);
- **videos de YouTube o Vimeo que cargan al dar clic**.

## Contexto

- El sitio (ADR 0114) solo ordenaba, ocultaba y titulaba las secciones del sistema, con
  texto propio en la portada, «Nosotros» y el contacto, y banners. No había dónde poner
  una galería, preguntas frecuentes, testimonios o un video.
- Lo operativo (horarios, precios, servicios, equipo, reseñas, sedes) debe seguir
  saliendo del sistema, nunca copiarse al sitio.
- Seguridad: nada de HTML ni JavaScript pegado, fotos solo subidas desde el editor y
  enlaces validados.

## Decisión

Se hace en tres fases, cada una en su bloque con su PR:

1. **Bloques propios en la página** (este bloque, 45).
2. **Varias páginas**: Inicio y páginas propias con su dirección, su menú, su pie de
   página y su SEO (título, descripción e imagen para compartir).
3. **Estilo global y editor visual**: colores, tipografía de una lista, esquinas,
   encabezado y botón principal; vista previa en vivo con clic en un bloque para
   editarlo; volver a una versión publicada.

### Fase 1: bloques propios

**Tipos** (`CatalogoSitioWeb::BLOQUES`):

| Bloque | Lleva |
| --- | --- |
| Texto con imagen | Título, texto, una foto a la izquierda o a la derecha y un botón opcional |
| Galería | Hasta 12 fotos con su pie |
| Preguntas frecuentes | Hasta 20 preguntas con su respuesta |
| Testimonios | Hasta 12: lo que dijo y su nombre |
| Llamado a la acción | Título, texto y un botón |
| Video | El enlace de YouTube o Vimeo y una portada opcional |
| Beneficios | Hasta 12 puntos con su detalle |
| Formulario | Uno de sus formularios de captación (ADR 0117) |

**Dónde van.**

- Entre la portada y el contacto, mezclados con las secciones del sistema y en el orden
  que el negocio quiera. Se repiten, se duplican, se ocultan y se quitan.
- Cada uno lleva su `id` (lo conserva al guardar; un id repetido al duplicar recibe
  otro).
- Cambiar de plantilla los conserva, en su orden, antes del contacto.

**Estilo por bloque.**

- Fondo: normal, suave, el color de la marca u oscuro.
- Alineación: izquierda o centro.
- Sobre el color de la marca o sobre oscuro, el botón se invierte para que se lea.

**Forma fija, no EAV.**

- El bloque se guarda en la misma configuración del sitio (`sitio_web` y
  `publicaciones_sitio_web`, JSON validado).
- Sus `datos` tienen campos fijos por tipo; la API guarda solo los de su tipo y quita lo
  vacío. Por ejemplo, una pregunta sin texto no se guarda.
- Un bloque sin contenido (una galería sin fotos, un video sin enlace) no sale en la
  página.

**Seguridad.**

- Todo es texto: la página lo pinta como texto, sin HTML.
- Fotos solo subidas desde el editor (las de la galería, los testimonios y la portada
  del video también). Al guardar o publicar se borran las que ya nadie usa.
- Botones con la misma regla de los banners: una página `https://`, una ruta del sitio
  (`/…`) o una sección (`#…`). Nunca `javascript:`.
- Video:
  - se guarda solo el proveedor y el id (`VideoSitio`), y el público recibe solo eso;
  - el reproductor se arma con `youtube-nocookie.com` o `player.vimeo.com?dnt=1`;
  - antes del clic se muestra una portada propia o un fondo del color de la marca;
    nada se carga de YouTube o Vimeo (ni la miniatura) hasta que la persona le da clic,
    y se avisa desde dónde carga.
- El formulario debe existir al guardar. En la página solo aparece si el negocio recibe
  prospectos (Captación encendida).

**Límites (parámetros).**

- `sitio.bloques_maximos`: 20 por omisión, de 1 a 60.
- `sitio.imagenes_maximas` sube de 30 a 40 por las galerías.

## Consecuencias

- Sin migraciones: la configuración del sitio ya era JSON. Un sitio guardado o publicado
  antes sigue igual: no tiene bloques.
- Un negocio arma una página con contenido propio sin perder lo que sale del sistema.
- Fases 2 y 3: varias páginas, estilo global, editor visual y volver a una versión
  publicada (pendientes del ADR 0114).
