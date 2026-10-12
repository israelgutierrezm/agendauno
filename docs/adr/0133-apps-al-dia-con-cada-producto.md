# ADR 0133 — Las apps al día con cada producto y con la web

Estado: Aceptado (2026-10-11). Pedido del dueño: «revisa […] las apps, que estén
actualizadas y completas». Decisión del dueño sobre iOS: «prepararlo y documentarlo».

## Contexto

La revisión comparó la app con la web y la API, pantalla por pantalla. La cuenta del
cliente estaba cerca de la web. Las fallas estaban en la agenda del equipo, en los
pagos y en iOS:

- **TurnoUno, quien imparte:** la agenda abría el día del primer profesional del
  equipo, casi siempre vacío, porque el servidor solo le da sus propias citas.
- **«Cobrar $X»** usaba el precio de hoy en el catálogo, no el total de la cita,
  fijado al agendarla.
- **iOS:** el proyecto tenía fijos el nombre «AgendaUno» y el bundle id
  `com.agendauno.app`, sin esquemas. TurnoUno no se podía compilar como su propia
  app, y con `default-flavor` ni AgendaUno compilaba.
- **Atrás de la web:**
  - sin OXXO, sin domiciliar al pagar, sin aviso de pago en proceso ni del modo de
    prueba;
  - sin historial de compras;
  - sin fichas de datos (formularios);
  - sin la inscripción antes de comprar;
  - la hoja de la cita sin nota, contacto, historial ni confirmaciones;
  - el pase de lista sin lista de espera;
  - tocar una notificación no hacía nada.
- **Producto:** en TurnoUno la pestaña decía «Reservas». La sesión suponía clases si
  el servidor no decía la modalidad.
- **Pruebas:** la suite solo se corría como AgendaUno.

## Decisión

**Agenda del equipo:**
- En citas, quien imparte abre su propio día, sin elegir a otros. Al resto se le abre
  el primer profesional con citas ese día.
- La semana se recorre con flechas y «Hoy»; el día vuelve a hoy al cambiar de sesión.
- Hoja de la cita, como en la web:
  - lo que se cobra es `cita.total_minor` (con su moneda);
  - cobrar, «Llegó» y «No asistió» se confirman antes, con su efecto;
  - la asistencia respeta `asistencia_desde` (ADR 0101);
  - al cancelar se elige quién cancela y se ve la vista previa;
  - muestra la nota, quién asiste, el contacto (si se puede ver) y el historial.
- Pase de lista: la lista de espera en su orden, «Ofrecer lugares» y aceptar a nombre
  de la persona el lugar que se le ofreció.
- «Espacios libres hoy» lleva a «Ver agenda». La app no agenda citas: crear o mover
  citas, agregar a una clase, escanear el pase y registrar Wellhub/TotalPass siguen
  en la web.

**Cuenta del cliente:**
- Pagar:
  - con tarjeta o con ficha de OXXO (referencia, código de barras y recibo);
  - en una renovación, dejar el pago automático, también por una persona a cargo;
  - aviso del pago en proceso y del modo de prueba;
  - una línea para otra persona dice para quién.
- La inscripción se dice antes de comprar.
- Historial de pagos, de 10 en 10.
- Fichas de datos en el Expediente, con sus seis tipos de campo.
- En citas, la pestaña dice «Citas», se aparta un horario y se activa un bono.

**Notificaciones:**
- Al tocar una, la app abre lo suyo según su `tipo`:
  - de una reserva o cita: sus reservas para el cliente; la agenda para el equipo;
  - de un cobro: sus pagos;
  - lo demás: el Inicio.
- Funciona también si la notificación abrió la app.
- Una notificación de otro negocio no cambia la pantalla.

**Sesión:**
- Sin modalidad del servidor, la sesión toma la de la app: citas en TurnoUno.
- Si `/yo` responde 404 y el negocio existe en el otro producto (la plataforma le
  cambió la modalidad), el aviso dice con qué app entrar.

**iOS:**
- Configuraciones `Debug`, `Release` y `Profile` para cada sabor (`-agendauno`,
  `-turnouno`), en el proyecto y en cada target.
- Esquemas compartidos `agendauno` y `turnouno`.
- Cada configuración lleva su `PRODUCT_BUNDLE_IDENTIFIER` y `APP_DISPLAY_NAME`, que
  es el nombre en el teléfono.
- `flutter build ipa --flavor turnouno` funciona como en Android.
- No se probó en una Mac. Antes de publicar falta: la firma, `com.turnouno.app` en
  el portal de Apple y sus íconos.

**Pruebas:** la suite no depende del producto. CI la corre también con
`--dart-define=PRODUCTO=turnouno`.

## Consecuencias

- Las dos apps operan el día como la web, sin montos ni días equivocados.
- TurnoUno tiene su app en iOS, lista para compilarse en una Mac.
- Google: TurnoUno necesita sus propios clientes OAuth, uno de Android y uno de iOS,
  en `GOOGLE_CLIENT_IDS_APP` (`docs/GOOGLE_APP.md`).
- Pendiente:
  - la hora del negocio: la app usa la del teléfono, y la de la API necesita un
    paquete de zonas horarias (dependencia nueva, por aprobar);
  - escanear el pase QR en la app: necesita la cámara (dependencia nueva, por
    aprobar);
  - el logo y el color de cada negocio en la app;
  - los íconos definitivos de TurnoUno;
  - regenerar el enlace del calendario, el tema personal, ver un documento subido,
    «Cambiar mi lugar» y los filtros al reservar.
