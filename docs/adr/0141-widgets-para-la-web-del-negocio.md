# ADR 0141 — Widgets para la web del negocio

Estado: Aceptado (2026-10-12). Pedido del dueño: «Widgets de reservas, podría entrar en
Captación. Un widget permite mostrar parte de AgendaUno dentro de la web existente del
estudio». Primera versión: calendario de clases y planes.

## Decisión

**Dos widgets, sin otro motor de reservas.** Reutilizan lo público que ya existe:

- **Calendario de clases** (solo negocios de clases).
  - Es el calendario público (M02): fecha, actividad, instructor y lugares libres, con
    los filtros de sucursal y actividad.
  - «Pedir lugar» abre ahí mismo el formulario de captación (ADR 0117) con esa clase
    como interés.
  - Quien ya tiene cuenta entra para reservar.
- **Planes** (clases y citas; en citas, bonos).
  - Usa las tarjetas de la página del negocio, extraídas a `TarjetaPlanPublico`: precio,
    vigencia con fecha, lo que incluye y sus restricciones (primera visita, permanencia,
    sede, actividad).
  - «Comprar» pide sus datos ahí mismo con el plan como interés, o lleva a la página del
    negocio si no capta prospectos. Quien ya tiene cuenta entra para comprar.

**Configuración** (Captación → «Integrar en mi web», `/captacion/widgets`, permiso
`prospectos.gestionar`):

- el tipo, un nombre, los filtros iniciales (sucursal y, en el calendario, actividad);
- el tema (claro, oscuro o como el visitante) y un color de acento, con texto legible
  sobre él;
- las webs donde se pondrá: se guardan como dominio, y vale cualquier subdominio;
- la vista previa (el widget mismo, con `vista_previa=1`) y el código para copiar.

El código es un iframe que avisa su altura (`agendauno:altura`), como el formulario
embebido: se ajusta solo a la web.

**Dónde se muestra.**

- La página del widget (`/w/{negocio}/{widget}`) va sin encabezado ni pie.
- Con webs registradas, si la web que la inserta (el navegador lo dice por
  `ancestorOrigins` o `referrer`) no es una de ellas, dice que no está autorizado.
- Sin webs registradas, o abierto directo, se muestra.
- No es una barrera de seguridad: lo que muestra ya es público. Evita que aparezca donde
  el negocio no lo puso.
- Apagado, o con la página del negocio cerrada, `GET /widgets/{widget}` responde 404 y
  el widget dice que no está disponible.

**Teléfono.** Una sola columna, todo con toques (sin depender de pasar el mouse); el
calendario ya era así.

## Consecuencias

- nginx deja embeber `/w/{negocio}/{widget}` en cualquier sitio (sin `X-Frame-Options`
  y con `frame-ancestors *`), como los formularios `/f/…`. Al principio se quedó fuera
  y en producción el widget salía bloqueado, tanto en la web del negocio como en la
  vista previa del panel. El CI lo revisa con el contenedor de nginx.

- Migración de negocio 000132 (`widgets`).
- La página del negocio y el widget comparten la tarjeta de plan: un cambio se ve en los
  dos.
- Pendiente si se pide: una lista de próximas clases compacta y un botón suelto de
  «Agendar» para negocios de citas.

## Cada botón dice lo que hace (2026-10-20)

Antes de V1, ningún botón público promete algo que no hace:

| Antes | Ahora | Qué hace |
|---|---|---|
| Widget de planes «Comprar» | «Me interesa» (sin captación: «Ver en la página del negocio») | Deja sus datos para ese plan (Prospectos) |
| «Reservar primera clase» | «Pedir mi primera clase» | Abre «Pide tu acceso» |
| «Quiero este lugar» | «Pedir este lugar» | Deja sus datos para esa clase |
| «Lo quiero» (planes de la página) | «Me interesa» | Deja sus datos para ese plan |
| «Reserva tu clase» (página de enlaces) | «Ver clases y horarios» | Lleva a la página del negocio |
| «Agendar y pagar» | Igual | Aparta la cita y pasa directo al pago; si no arranca, queda «Pagar ahora» |

- El enlace «¿Ya tienes cuenta? Entra…» del widget también se ve con el formulario
  abierto y lleva la clase o el plan elegidos en el regreso (ADR 0142). Antes solo se
  veía sin nada elegido, así que nunca llevaba el interés.
- Que el widget termine en una reserva o una compra (no solo en un prospecto) queda para
  V1.1, junto con la inscripción inmediata.
