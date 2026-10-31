# ADR 0230 — Revisión de la agenda para producción: bordes de sede, zona, lista de espera, cancelación y candados

Estado: Aceptado (2026-10-30). Corrige hallazgos de la revisión para producción sobre las
ADR 0033 (reglas de agenda), 0065 (citas por pagar), 0101 y 0166 (inasistencia automática),
0116 (regla única de cupo), 0148/0149/0197/0202 (plataformas), 0160/0176 (importar citas y
reservas), 0182 (pases de invitado), 0217 (quién da cada servicio), 0218 (prepagados) y 0223
(agenda de clases completa).

## Contexto

El núcleo de la agenda estaba bien: candados de sesión, profesional y derecho, retenciones e
idempotencia. La revisión encontró bordes:

- Un día cerrado cerraba **todas** las sedes y lo podía crear cualquiera con
  `agenda.gestionar`, aunque solo viera una sede.
- Cambiar la zona horaria de una sede no movía lo ya programado: las fechas guardan su instante
  en UTC y una zona copiada, así que quedaban a otra hora de reloj. Reprogramar leía la hora
  con la zona copiada; agendar y reprogramar desde la cuenta, con la de la sede.
- Al cancelar el negocio una clase, sus invitados y sus lugares de plataforma quedaban
  `reservado`: el pase se seguía contando y la reserva terminaba en «no asistió» (Fitpass pedía
  marcarlo así en su panel).
- La lista de espera no se atendía al subir un cupo, ni al liberarse los lugares de un canal, y
  una reserva nueva podía tomar un lugar libre antes que quien esperaba. Además la oferta
  regalaba los lugares apartados para otro canal.
- Agendar sin cuenta con el correo de un cliente gastaba su prepago y le contaba para su límite.
- Un pago tardío reconfirmaba una reserva vencida aunque la persona ya hubiera vuelto a reservar:
  dos lugares y dos cobros, sin incidencia.
- Una cita podía pasar a quien no da ese servicio, o a quien no es profesional, sin candado y
  sin aviso.
- El check-in automático buscaba la clase en todas las sedes, aunque la conexión es de una.
- Importar citas y reservas retenía los candados de todas las filas, también en la vista previa.
- Orden de candados distinto entre caminos, deadlocks que salían como 500 y asientos del libro
  sin el candado del derecho.
- Kiosco: «la primera» reserva sin orden; con clases seguidas marcaba la siguiente y la que estaba
  en curso quedaba como falta.
- La inasistencia automática solo veía el último día: tras una caída de más de 24 horas, esas
  reservas quedaban sin asistencia y su crédito apartado para siempre.

## Decisiones del dueño

1. **Días cerrados por sede.** Cada día es de una sede o de todo el negocio
   (`excepciones_horario.sucursal_id`, null = todo el negocio), uno por fecha y sede. Crear o
   quitar uno de todo el negocio es solo de quien ve todas las sedes
   (`sucursalesPermitidas() === null`, sin estar acotado a sus propias clases); uno de una sede,
   de quien puede operarla. Cancelar lo afectado solo toca esa sede. Disponibilidad de citas,
   `cabeEnHorario`, generación de series, importar clases y ocupación de la agenda aplican el de
   su sede o el del negocio. La pantalla ofrece «Todo el negocio» o la sede (a quien está acotado,
   solo sus sedes) y la lista dice de cuál es cada día.
2. **Cambiar la zona de una sede re-ancla y avisa.** Las fechas programadas por venir conservan su
   hora de reloj: las 07:00 siguen siendo las 07:00. Sin confirmar no cambia nada y el API responde
   409 con cuántas fechas y reservas son; con `confirmar_zona`, re-ancla y le avisa a quien tiene
   reserva. Reprogramar interpreta siempre con la zona de la sede.
3. **Cuando el negocio cancela una clase se devuelve.** Sus invitados `reservado` se cancelan (vuelve
   el pase del mes) y sus lugares de plataforma también: Wellhub y TotalPass con
   `cancelarPorElNegocio`; Fitpass, que no tiene API, va a una lista «por cancelar en su panel». Si
   el anfitrión cancela su propia reserva, el pase sigue como hasta hoy.
4. **La lista de espera siempre tiene prioridad.** Todo lugar que se libere se ofrece primero a la
   lista: al cancelar, al subir el cupo de una fecha o de la serie y al liberarse un canal. Bajo el
   candado de la sesión, una reserva nueva no toma un lugar si hay gente esperando a quien no se le
   ha ofrecido: primero se ofrece y después se evalúa.

## Lo que se corrigió sin necesidad de decisión

- **Citas sin cuenta.** Desde la página pública siempre se paga (no se usa lo pagado por adelantado
  de esa ficha) y su límite (`citas.maximo_por_pagar`) cuenta solo las agendadas sin cuenta; las de
  la cuenta se cuentan aparte, así un anónimo no le bloquea el límite a la ficha.
  `reservas.sin_cuenta` dice de cuál es cada una.
- **Canales en la lista de espera.** `promover` corta por candidata con lo que su canal puede tomar:
  no regala lo apartado para otro canal.
- **Pago tardío.** Si la persona ya tiene otra reserva viva en esa clase, o una cita que se le encima,
  no se reconfirma: queda como `PAGO_TARDIO` por conciliar.
- **Quién atiende una cita (ADR 0217).** Reprogramar (por la reserva o por la sesión) y
  `PUT /sesiones/{s}/instructor` exigen que atienda citas y dé ese servicio. Asignar una cita toma el
  candado de la sesión, no acepta quedarse sin profesional y avisa con el profesional nuevo.
- **Check-in automático por sede.** Solo busca clase en la sede de la conexión por la que llegó el
  aviso.
- **Importaciones sin candados largos.** La revisión de todo el archivo (la vista previa y el primer
  paso de importar) corre en una transacción que siempre se revierte y sin candados
  (`RevisionSinCandados`): `for update` no se compila, la fila del profesional y del recurso no se
  pone en fila y, en MySQL, se apagan las revisiones de llave foránea de esa conexión, que toman un
  candado compartido de la fila padre (el profesional de una sesión nueva). Lo que pasa la revisión se
  guarda en lotes de 20 filas, cada uno con sus candados y su transacción.
- **Orden único de candados:** órdenes → pago → sesión → reserva → profesional → recurso → derecho →
  retención. Lo cambiaron `cancelar`, `expirarOfertasVencidas`, `expirarReservasPendientes`,
  `reconfirmarPagoTardio`, `transferir`, `moverCita` (también profesional antes que recurso),
  `moverAClase`, `InvitadosTenant::cancelar` y el cobro de una cita en caja (sus órdenes antes que
  la reserva, como al cancelarla y al aprobar su pago). `cancelarSesion`, `cambiarLugar`,
  `moverClase`, los dos caminos de expirar y `promoverCupos` dan 3 intentos. Lo que agota sus
  intentos (SQLSTATE 40001, error 1213, candado vencido) responde **409 `CONCURRENT_CONFLICT`**
  «Inténtalo de nuevo», no 500.
- **`transferir`.** Revisa duplicados bajo el candado de la sesión y rechaza citas: su orden y su
  prepago siguen siendo del titular y su enlace firmado sigue controlando la cita.
- **Libro de créditos.** `confirmar` y `perder` bloquean el derecho y después la retención, como
  `retener`: `saldo_posterior` queda bien con dos asistencias a la vez y el cierre de ciclo ya lee
  saldo y apartado sin que se le cuele un asiento.
- **Cobro abierto en la pasarela.** Cancelar una reserva por pagar, o la clase o cita completa, cierra
  el intento en la pasarela una vez confirmada la transacción (como al vencer): si se revierte, no se
  cierra nada.
- **Elegir plan sin candado.** Si al apartar el crédito el plan ya no alcanza, se vuelve a resolver
  sin ese plan, con su tope revalidado; sin otro, el saldo insuficiente se responde tal cual.
- **Kiosco y acceso.** Con varias reservas en la ventana, primero la clase en curso y luego la que
  empieza antes.
- **Inasistencias tras una caída.** Se recuerda por negocio hasta dónde llegó la última vuelta
  (`marcas_de_proceso`) y se retoma desde ahí, con tope de `asistencia.dias_retomar_inasistencias`
  (7 días, de plataforma). Se respeta el motivo de la ADR 0101: encender la opción no marca nada
  hacia atrás. Apagada, cada vuelta adelanta la marca; encendida, nunca se va antes de cuándo se
  encendió (`parametros_negocio.updated_at`), ni de que el negocio reabriera (ADR 0166). La marca solo
  avanza si la vuelta terminó. Sin marca (la primera vez), un día atrás, como hasta hoy.

## Cómo se re-ancla

- `ReanclarZonaDeSedeTenant` recorre las fechas por venir de la sede cuya zona copiada no es la de la
  sede, cada una con su transacción corta, así que un corte a medias se termina volviendo a correrlo:
  **la zona copiada en la sesión dice si ya se re-ancló**.
- `ReprogramarTenant::reanclarZona`: bajo el candado de la sesión recalcula su instante (misma hora de
  reloj y misma duración), rehace los recordatorios como al reprogramar y emite `reserva.reprogramada`
  a quien tiene lugar. Como la hora de reloj no cambia, el aviso dice las dos zonas («07:00 (hora de
  Tijuana)»). Si las dos zonas marcan lo mismo ese día, el instante no cambia y no se avisa.
- Hasta 50 fechas se hace en la misma solicitud; con más, o si falla a medias, en el trabajo
  `ReanclarZonaDeSede`, con el negocio en su payload.
- **Horarios de atención y series guardan hora local: no se mueven.** Los **bloqueos** de la sede y de
  sus salas guardan instantes y su zona copiada: se re-anclan igual, también idempotentes. Los de una
  persona van en la zona del negocio y los que trae un calendario externo son instantes de allá: no se
  tocan.
- Las plataformas reciben el cambio al momento si la clase tiene reservas (`reserva.reprogramada`) y,
  si no, en su vuelta de cada cinco minutos (la firma del horario cambió); los calendarios externos,
  en la suya.

## Consecuencias

- `GET /excepciones-horario` agrega `sucursal_id`, `sucursal` y `editable`: lo que ya leían sus
  consumidores no cambia. La app móvil no lee días cerrados.
- `excepciones_horario.sede_clave` es una columna calculada (la sede o 0) solo para el índice único:
  uno por fecha y sede, también de todo el negocio, porque un índice único trata cada NULL como
  distinto.
- `agendauno:expirar-ofertas` además barre las listas de espera de las clases por venir con lugar. Es
  lo que atrapa los lugares que se liberan sin que nada lo anuncie (la hora de un canal) y lo que una
  reserva rechazada dejó de ofrecer.
- Quitarle el tope a una clase (`capacidad` null) ofrece el lugar a toda su lista.
- Una reserva que estaba al tanto de un lugar libre puede recibir `CAPACITY_FULL` bajo el candado
  aunque la vista previa dijera que había: ese lugar era de la lista.
- Una importación grande cuesta el doble (revisar y guardar) y deja de ser atómica ante una carrera:
  sigue siendo todo o nada ante lo que la revisión puede ver, pero si en el segundo entre revisar y
  guardar un lote otra cita ocupa un horario, ese lote no entra y lo anterior sí. Se responde cuántas
  ya entraron y volver a subir el archivo no las duplica.
- `agendauno:verificar-concurrencia` suma dos casos: cancelar una reserva contra cancelar su clase, e
  invitado contra reserva por el último lugar. Corre en CI con MySQL.

## Pendiente

- `ImportarClasesTenant` sigue en una sola transacción (también su vista previa), con candado de todas
  las sedes. No estaba en el hallazgo y tiene su propio tope (`importaciones.max_sesiones_agenda`).
- Al cancelarse la clase de un invitado cuyo anfitrión no tiene reserva en ella, el anfitrión no recibe
  aviso: su pase vuelve, pero `reserva.sesion_cancelada` es por reserva. Requiere una plantilla nueva.
- Los pases y reservas de plataforma de clases canceladas **antes** de este cambio: el pase ya no se
  cuenta (`usadosEnElMes` no mira las canceladas) y los lugares se cierran en la siguiente vuelta de
  plataformas, sin migrar datos.
