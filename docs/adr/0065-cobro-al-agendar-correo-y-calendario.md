# ADR 0065 — Cobro al agendar, correo obligatorio, calendario y cliente con cuenta

Estado: Aceptado (2026-09-28).

## Contexto

Al revisar la página pública para agendar salieron estos puntos:

- **Cobro**: toda cita en línea se apartaba hasta pagarla. Si el negocio no tenía
  pasarela activa, el cliente no podía pagar y la cita se vencía sola.
- **Correo**: era opcional y no se mandaba nada al agendar. La confirmación salía
  solo al pagar, y un invitado sin correo no quedaba ligado a su cuenta si luego se
  registraba.
- **Calendario**: el calendario nativo dejaba elegir días pasados y días en que nadie
  atiende.
- **Cliente con cuenta**: quien ya tenía cuenta volvía a escribir sus datos en la
  página pública.

## Decisión

- **Cobro por negocio**: parámetro `citas.pago_en_linea_obligatorio` (sí/no, por
  negocio, inicial sí), evaluado por `CobroDeCitasTenant`.
  - **Sí, con pasarela activa**: la cita que agenda el cliente se aparta hasta
    pagarla (como antes) y se vence si no se paga.
  - **No, o sin pasarela activa**: la cita queda confirmada al agendar con su orden
    por cobrar (`ReservasTenant::reservarPorCobrar`, lo mismo que cuando agenda el
    negocio). El cliente la paga en línea si quiere o en la sucursal.
  - Aplica a la página pública, la cuenta web y la app.
  - Las opciones de cita traen `cobro: {pago_obligatorio, pago_en_linea}` para la
    pantalla.
- **Correos**:
  - al apartar, evento `reserva.apartada` con correo: qué, cuándo, dónde, cuánto y
    hasta qué hora pagar (`vence`), editable en Comunicación → Automáticos;
  - confirmada al agendar: el correo de confirmación que ya existía
    (`reserva.confirmada`);
  - en la página pública el correo es obligatorio. Con el mismo correo, sus citas
    como invitado se ligan a su cuenta si se registra (ADR de registro, fase 1.5).
- **Calendario**: `GET /citas/dias?sucursal_id&desde&dias[&instructor_id]` (hasta 62
  días) marca qué días se pueden elegir.
  - Hoy o después.
  - Que alguien atienda ese día de la semana (o la persona elegida).
  - Que el negocio no haya cerrado ese día.
  - Hoy, solo si todavía queda atención.
  - La página muestra una tira de días desde hoy, con «Más fechas».
  - Abre en el primer día con atención; los demás días no se pueden elegir.
  - Las horas pasadas y las ocupadas ya no se ofrecían.
  - En la cuenta y la app también (`GET /mi/citas/dias`, que funciona aunque el
    negocio no esté en el directorio). La web usa el componente `CalendarioDias`
    en la página pública y en Mi cuenta; la app bloquea esos días en su calendario.
- **Cliente con cuenta**:
  - En la confirmación, «¿Ya tienes cuenta? Entra».
  - Lo elegido se guarda en `sessionStorage` y se retoma al volver.
  - `EntrarView` regresa a `volver`, que solo acepta rutas internas.
  - Con sesión de cliente en ese negocio, se agenda por `/mi/citas` sin pedir datos.
  - Queda «Agendar con otros datos» para agendar como invitado.
  - «Para otra persona» queda pendiente: sin revivir familias (ADR 0059).
- **Tarjetas de sede**: contenido centrado e iconos de Instagram y Facebook de la sede
  si los tiene. Abrirlos no elige la sede.

## Consecuencias

- Un negocio sin pasarela ya no pierde citas en línea.
- Un negocio que no quiere cobrar por adelantado recibe citas confirmadas.
- **Límite de citas por pagar** (actualización): parámetro `citas.maximo_por_pagar`
  (por negocio, inicial 5, 0 = sin límite).
  - Con esas citas próximas sin pagar (apartadas o confirmadas por cobrar), el
    cliente ya no agenda otra en línea ni desde su cuenta: `LimiteCitasPorPagar`,
    422.
  - Se cuenta bajo el candado de su ficha, para que dos solicitudes a la vez no lo
    rebasen.
  - Con «cualquier profesional» no se reintenta con otro.
  - El negocio sí puede agendarle; las reservas no guardan quién las agendó, así que
    esas citas también cuentan.
- **Enlace para pagar después** (actualización): el correo de apartado trae
  `{{enlace}}` → `/agendar/{slug}?pagar={orden}`.
  - La página consulta `GET /citas/orden/{orden}`; el ULID de la orden es la
    capacidad, como al pagar, y no expone datos de la persona.
  - Muestra qué, cuándo, dónde, cuánto y hasta qué hora pagar, con «Pagar ahora».
  - Si ya está pagada, lo dice; si venció, ofrece agendar de nuevo.
  - El texto inicial de la plantilla se actualiza solo si el negocio no lo editó.
