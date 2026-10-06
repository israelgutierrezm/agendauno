# ADR 0101 — Asistencia: ventana para pasar lista, retardos e inasistencia automática

Estado: Aceptado (2026-10-06). Complementa el ADR 0034 (asistencia y créditos), el
ADR 0042 (parámetros configurables) y el ADR 0043 (tolerancia de inasistencias).

## Contexto

En la revisión por roles, una instructora pudo marcar asistencia cinco horas antes de
la clase. Quedaba sin registro quien nadie marcaba, así que «sin registrar» se
acumulaba y la política de inasistencias no se aplicaba. Tampoco había forma de decir
que alguien llegó tarde.

## Decisión

- **Ventana para pasar lista** (`asistencia.minutos_antes`, 30 min por omisión,
  configurable por el negocio y la plataforma): la asistencia se registra desde esos
  minutos antes de que empiece la clase o cita. Antes, `422 ATTENDANCE_NOT_OPEN` con
  la hora («La asistencia se registra desde las 07:30.»). Las correcciones posteriores
  siguen permitidas. La agenda (`asistencia_desde`) y la lista de una clase
  (`meta.asistencia_desde`, `meta.empezo`) lo dicen para que las pantallas lo
  muestren antes de intentarlo.
- **Retardos**: «presente» puede llevar `retardo`; cuenta como asistencia (mismo
  crédito, mismas reglas) y se corrige sin mover créditos. «No vino» nunca lleva
  retardo. Se guarda en `asistencias.retardo`.
- **Terminar lista** (`POST /sesiones/{sesion}/terminar-lista`, con
  `asistencia.marcar`): desde que empieza la clase o cita, quien sigue sin registro
  queda «no se presentó», con la política de inasistencias (tolerancia y cobro).
- **Inasistencia automática al terminar** (`asistencia.no_asistio_al_terminar`, sí por
  omisión): `agendauno:marcar-inasistencias` (cada 5 minutos) marca «no se presentó» a
  quien no tiene registro en las clases y citas que terminaron en el último día. Lo
  que ya marcó alguien no se toca. Se guarda como `asistencias.automatica` y las
  pantallas lo dicen («No se presentó (automático)»).
- **Pantallas**: «Llegó / Retardo / No vino» en Recepción, en el pase de lista de
  quien imparte y en el detalle de una clase de la agenda; «Llegó tarde» en la cita;
  los botones se activan en la ventana; «Terminar lista» aparece ya empezada la clase
  si falta alguien. Igual en la app.

## Consecuencias

- Con la inasistencia automática, «Sin registrar» (ADR 0100) dura a lo más unos
  minutos tras terminar; las faltas se cobran o toleran según la política.
- Solo se marcan las que terminaron en el último día: activar la regla no castiga de
  golpe el historial viejo sin registro.
- Las pruebas del API abren del todo la ventana (valor de plataforma en
  `tests/Pest.php`) porque, con el reloj fijo, marcan asistencia en clases que aún no
  empiezan; la ventana tiene sus propias pruebas con el valor del negocio.
