# ADR 0110 — La app instalable (PWA) de cada negocio

Estado: Aceptado (2026-10-10). Parte del ADR 0108 (plataforma multiproducto).

## Contexto

Cada negocio (de AgendaUno o de TurnoUno) debe poder ofrecer a sus clientes una app
instalable con su identidad, sin publicar nada en las tiendas: abrir en su negocio sin
elegirlo, con su nombre, su ícono y su color. Debe ser una sola implementación para
todos los negocios, sin un proyecto por cliente, y no debe filtrar datos entre
negocios ni aparentar reservas sin conexión.

## Decisión

- **Un origen por negocio.** La PWA vive en el subdominio del negocio
  (`{slug}.agendauno.mx`, `{slug}.turnouno.mx`). Cada subdominio es un origen distinto:
  su service worker, su caché y su sesión no se comparten con otro negocio. Fuera del
  subdominio (el dominio de un producto, desarrollo) no hay PWA.
- **Manifiesto dinámico** (`GET /api/v1/pwa/manifest.webmanifest`, en el subdominio):
  nombre y nombre corto del negocio, su descripción, `start_url` `/?origen=app`,
  `scope` `/`, `id` `/`, `display` `standalone`, el color de su marca (o el del
  producto) y su logo con su tamaño real, más los íconos del producto como respaldo
  (`/assets/pwa/{producto}-192|512|512-maskable.png`). Solo en el dominio de su
  producto (ADR 0108).
- **Color de marca** (`estudios.color_marca`, `#rrggbb`): lo elige el dueño en
  Configuración → Perfil público; es la barra de la app instalada. Sin él, el del
  producto.
- **Service worker propio** (`/sw.js`, sin dependencias): nunca intercepta la API
  (`/api/`) ni los archivos de los negocios (`/storage/`); las páginas van primero a
  la red y, sin conexión, muestran «Sin conexión» (nunca una página vieja ni una
  reserva confirmada sin el servidor); solo guarda los recursos con hash de la
  compilación. Se registra solo en producción; versión en `VERSION` (al cambiarla se
  borran las cachés anteriores). nginx lo sirve sin caché.
- **Instalar**: la web enlaza el manifiesto y las etiquetas de iOS (título, ícono de
  inicio con el logo del negocio, color) y muestra una invitación discreta en la página
  del negocio y en el inicio del cliente: con el aviso del navegador (Android, Chrome,
  Edge) o, en iPhone, cómo «Agregar a inicio». «Ahora no» se recuerda.

## Pendiente

- Los íconos del logo se usan tal cual subió el negocio (sin recortar a cuadrado): la
  imagen de la API no tiene GD. Si se agrega (cambio de imagen), el manifiesto puede
  servir el logo en 192 y 512 cuadrados.
- TurnoUno aún no tiene logotipo: su ícono de respaldo es provisional (su inicial).
- Notificaciones push web: no implementadas (la app oficial sí las tiene); requieren
  llaves VAPID y un servicio de envío.
- El constructor del sitio (plantillas, secciones, banners) sigue en el escaparate
  actual; el color de marca es el primer dato de identidad.
