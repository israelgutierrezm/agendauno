# ADR 0100 — Pendientes concretos, citas como citas y el «hoy» del negocio

Estado: Aceptado (2026-10-06).

## Contexto

Una revisión de la operación diaria encontró que el tablero obligaba a buscar de
nuevo, que mezclaba dos pendientes distintos y que trataba a las citas como clases:

- Desde el Inicio, «Falta pasar lista» o «Falta marcar si llegó» llevaban a la agenda
  o a Recepción en general, no a esa clase o cita.
- «Por atender» contaba también las citas que ya terminaron sin que nadie registrara
  la llegada: un día con 18 citas pasadas sin registro decía «Por atender: 18».
- Las citas se presentaban con su cupo (`1/1`, y «Llena» en la semana) en lugar de
  duración, profesional, llegada y pago.
- Varios controles de «Hoy» y los periodos por omisión usaban el día del navegador,
  no el del negocio (ADR 0099).
- La reserva pública ofrecía en una sede a profesionales que solo atienden en otra.

## Decisión

- **Abrir el pendiente.** La agenda acepta `?fecha=&sesion=` y abre esa clase o cita;
  Recepción acepta lo mismo y deja esa clase a la vista para pasar lista. Desde el
  Inicio cada fila abre su sesión, «Pasar lista» va a Recepción con la clase y
  «Marcar si llegó» abre la cita; la acción principal de la tarjeta de lo que sigue es
  «Ver clase / Ver cita» (la agenda completa queda al lado).
- **Por atender ≠ sin registrar.** Una cita sin registro está *por atender* mientras
  no termina y *sin registrar* cuando ya terminó (le falta el registro, no la
  atención). `GET /inicio/hoy`: `por_atender` cuenta solo las que no terminan;
  `pendientes_registrar`, las que terminaron sin registro. La web (`estadoCita` →
  `sin_registrar`), Recepción (indicador y filtro) y la app muestran ambos por
  separado.
- **Citas como citas.** En el Inicio, la agenda (semana y día) y la app, una cita
  muestra duración, profesional, si llegó y si falta cobrarla; el cupo y la barra de
  ocupación quedan para las clases (`pctOcupacion` es null en una cita).
- **El «hoy» del negocio en la web.** `hoyEnNegocio(sesion.zonaHoraria)` define el día
  de los botones «Hoy», del día que se pide al Inicio y a Recepción, del mes y del día
  por omisión de reportes, cortes y nómina, y del día marcado en los calendarios.
- **Profesionales por sede.** Las opciones para agendar (`/citas/opciones`,
  `/mi/citas/opciones`) traen por profesional las `sucursales` donde tiene horario de
  atención; la página pública y la cuenta del cliente solo ofrecen, en cada sede, a
  quien atiende ahí.

## Consecuencias

- La prueba de «cada cita en un solo estado» cambia: «por atender» ya no suma las
  pasadas sin registro.
- Un profesional sin horario en ninguna sede no se ofrece para agendar en línea (de
  todos modos no tendría disponibilidad).
