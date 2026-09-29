# ADR 0061 — Perfil público del negocio y de sus sedes, y página de enlaces

Estado: Aceptado (2026-09-28).

## Contexto

Se comparó el flujo público con AgendaPro: un link desde Instagram abre una página de
enlaces y luego el sitio de la sucursal. En AgendaUno faltaba:

- la portada, la descripción y las redes del negocio;
- en cada sede, la dirección con mapa, el WhatsApp, las redes propias (hay negocios
  con una cuenta por sucursal) y el horario;
- las fotos del equipo, que ya existían pero no se mostraban;
- una descripción por servicio o clase.

A un estudio de clases, como uno de pole, le falta además lo que publica en sus
redes: el horario semanal de clases y sus niveles.

## Decisión

- **Negocio** (control plane, junto al logo): `descripcion`, `portada_url` y `redes`
  (JSON con instagram, facebook, tiktok, youtube y sitio_web). Se editan en
  Configuración → Perfil público:
  - `GET/PUT /perfil-publico` y `POST/DELETE /marca/portada`, con
    `estudio.gestionar`;
  - el logo y la portada se guardan por separado: cambiar una imagen ya no borra la
    otra.
- **Sede** (base del negocio): `direccion`, `telefono`, `whatsapp`, `redes` y
  `horario` (`[{dia, abre, cierra}]`; el día que falta está cerrado).
  - Se editan en el mismo panel de la sede.
  - Si no hay horario capturado y el negocio atiende con citas, se publica el de sus
    profesionales en esa sede (lo más temprano que alguien empieza y lo más tarde que
    alguien termina).
- **Servicio o clase**: `descripcion`, editable en Catálogo. La categoría es la
  actividad del catálogo.
- **Redes** (`RedesSociales`): se capturan como usuario (`@casa_navaja`) o como enlace
  y se guardan como enlace completo.
  - Se rechaza un enlace de otra red o que no sea http(s): se muestra tal cual en
    páginas públicas.
  - El WhatsApp sale como `wa.me`; un número de 10 dígitos se toma como de México.
- **Escaparate público**: agrega lo anterior, con estas piezas:
  - `instructores` pasa a ser `[{nombre, foto_url}]`;
  - `servicios` trae descripción, categoría, duración, precio, niveles y si se agenda
    en línea;
  - `horario_clases` es el horario semanal de las clases recurrentes vigentes;
  - cada sede lleva `mapa_url` (Google Maps, por dirección o por coordenadas).
- **Página pública**: portada, descripción con «Leer más», redes y WhatsApp arriba;
  servicios o clases por categoría con buscador; horario semanal de clases; equipo
  con foto; sedes con «Cómo llegar», WhatsApp, teléfono, redes y horario.
- **Página de enlaces** (`/{slug}/enlaces`, y `/enlaces` en el subdominio del
  negocio), para la bio de Instagram:
  - logo, nombre, calificación y redes;
  - un botón por cada cosa que el negocio tenga: agendar cita y/o reservar clase,
    horario de clases, precios, sitio web, WhatsApp, cómo llegar a cada sede y
    conocer más;
  - pie con «Crea la página de tu negocio».
- **Agendar cita** muestra los servicios por categoría con su descripción.

## Consecuencias

- El negocio controla su presencia pública sin herramientas externas de «link in
  bio».
- El horario de clases y los niveles quedan visibles para quien llega de redes, sin
  crear cuenta.
- Pendiente (fuera de este paso): foto por servicio, «cualquier profesional
  disponible», varios servicios en una cita y avisos por WhatsApp.
