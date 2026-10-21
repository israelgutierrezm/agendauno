# ADR 0202 — Wellhub, TotalPass y Fitpass completos

Estado: Aceptado (2026-10-21). Completa los ADR 0148, 0149, 0185 y 0197.

## Contexto

Una revisión contra la documentación pública de cada plataforma (dev.totalpass.com,
developers.wellhub.com y lo que Fitpass y Fitune publican) encontró errores y huecos.
Este ADR junta lo que se cambió para que cada integración quede completa hasta donde
lo permite lo que la plataforma publica. La validación con credenciales reales sigue
pendiente: solo se probó con respuestas simuladas según esa documentación.

## TotalPass

Errores corregidos:

- **El token de confirmación del check-in se cortaba.** Su ejemplo
  (`…/webhook_confirmations/TXIefq…jrw==`) trae base64 con relleno `=`: se tomaba sin el
  `==` y TotalPass habría contestado 404 a todo check-in real. Ahora se toma todo el
  último tramo de la dirección y se acepta `=`, `+`, `.` y `~`.
- **El motivo de un rechazo no se leía.** TotalPass lo manda en `errors[0].source.label`
  (un objeto al confirmar un check-in, una lista al validar un token), no en
  `errors[0].label`. Ahora se leen las tres formas.
- **La ventana para reservar podía caer en el pasado.** Con «en cuanto se programa»
  (0 días) se mandaba la clase menos 60 días; TotalPass rechaza fechas pasadas
  («dates are not before today»). Ahora abre desde ahora (redondeado al minuto) o desde
  los días por adelantado del negocio, y el límite de cancelación nunca queda atrás. Para
  saber si una clase cambió se compara la ventana del negocio, no la hora en que se
  mandó; si las reservas ya cerraron, no se manda ventana y TotalPass conserva la suya.
- **La API de uso del token en pruebas.** Su base es `https://api.totalpass.com/service/v1`
  y en pruebas `https://staging.totalpass.com/api/v1`: la configuración ahora lleva la
  versión (una base vieja sin ella se completa con `/service/v1`).
- **Se le ofrecían lugares de más.** A los lugares libres se les sumaba también a los
  visitantes de TotalPass sin reserva; TotalPass solo descuenta sus reservas. Ahora se
  suman solo sus reservas vivas.

Huecos completados:

- **Validación a mano también con CURP o código de beneficiario** (`type` `curp` o `code`
  en `track_usages`), además del token del día, y los mensajes de `inactive_employee`,
  `check_in_started_on_different_gym` y `gym_not_found`.
- **Sus avisos no van firmados**, así que:
  - el check-in de otro gimnasio (`place.code` distinto del de la integración) se
    descarta, y los datos del visitante solo se guardan si TotalPass confirma el check-in
    (un aviso falso ya no cambia el nombre de nadie);
  - la reserva se comprueba con `GET /partner/slot` y se toman de ahí la clase y la
    persona: tiene que estar viva y ser de una clase publicada aquí. Su ejemplo de aviso
    trae un UUID en `event.id` y su consulta un `eventId` numérico: se acepta cualquiera
    de los dos si es la misma clase.
- **Conciliación de reservas** cada cinco minutos (`agendauno:sincronizar-plataformas`):
  con `GET /partner/slot` por periodo (hasta 30 días), una reserva viva cuyo aviso se
  perdió entra como si hubiera llegado (una sola vez) y un lugar cuya reserva TotalPass
  ya no tiene viva se libera (`CANCELADA_EN_TOTALPASS`).
- **Si no se pudo confirmar a tiempo** (5 minutos), se le pregunta a TotalPass: si la
  dejó viva, el lugar sigue; si no, se libera.
- **Los lugares no bajan de lo que TotalPass ya tiene reservado** (su 422): se leen sus
  `slotsInUse` y se mandan esos.
- **Retirar una clase** primero la deja `INACTIVE` (TotalPass borra sus reservas y la quita
  de su app) y luego la borra.
- **Avisos**: el de check-in se registra con `GET /partner/webhook/get` y `PUT …/update` si
  la sede ya tenía otro; al apagar la integración se quita (`DELETE …/delete/CHECKIN`). Al
  dejar de publicar se quita el de reservas (`DELETE /partner/webhook`) y se apaga la
  confirmación. Al registrarlo se comprueba con `GET /partner/webhook` que quedó el
  nuestro. Con otra llave de sede se vuelven a registrar.
- **Basta la llave de la sede** para activarla (check-in automático y reservas); la llave
  y el código del gimnasio siguen siendo para validar el token a mano.
- Si su sesión (JWT) vence antes de tiempo, se pide otra.

## Wellhub

- **Lugares al momento.** Wellhub pide actualizar los lugares en cada reserva, sea suya o
  no. Una reserva directa, una cancelación, una sesión cancelada o reprogramada de una
  clase publicada manda sus lugares en segundos (listener del outbox y un trabajo único
  por sesión que agrupa lo que pasa en 15 segundos), sin esperar la vuelta de cada cinco
  minutos. Lo mismo vale para TotalPass.
- **Borrar una fecha de una serie** que está publicada o tuvo lugares de plataforma ya no
  la borra (dejaba su horario huérfano y reservable en Wellhub): se cancela y se retira.
- **Negocio dado de baja:** se retira todo lo publicado y se ocultan sus clases.
- **El negocio cancela una reserva** desde la lista de la clase
  (`POST /lugares-plataforma/{lugar}/cancelar`, con `reservas.gestionar`): Wellhub con
  `CANCELLED_BY_GYM`, TotalPass con `DELETE /partner/slot/{id}`. El lugar se libera y se
  ofrece a la lista de espera.
- **Recepción ve quién reservó** desde la app de la plataforma en la lista de la clase
  (`meta.plataforma`) y marca que llegó (`POST /lugares-plataforma/{lugar}/llego`): con
  Wellhub se valida con su Wellhub ID ya guardado, sin teclear sus 13 dígitos; TotalPass
  valida la llegada sola en su app.
- **Avisos fuera de orden:** una cancelación que llega antes que su solicitud queda
  anotada y la solicitud ya no aparta lugar.
- **Al rendirse** con una reserva que no se pudo contestar a tiempo, se le dice a Wellhub
  `REJECTED` con `TECHNICAL_ERROR` (su categoría para fallas técnicas).
- **Avisos registrados al activarla** (no solo al publicar clases: un negocio que solo
  valida visitas también recibe el `checkin`), solo con la dirección HTTPS de la
  plataforma. Los que el gimnasio ya tenía (de otro sistema) se cambian con
  `PUT /webhooks/{evento}` en lugar de crearse otra vez. La llave de firma: hasta 100
  caracteres, como pide Wellhub.
- **Clases:** no se duplican (antes de crearla se busca la suya por referencia); al dejar
  de publicarla se oculta (`visible: false`, Wellhub no borra clases) y al volver se
  muestra. Una clase en línea (ADR 0184) va con producto virtual y su enlace al aceptar
  la reserva; si el producto y la clase no coinciden, se dice antes de publicar. Un
  horario de más de 200 minutos no se publica (antes se recortaba sin avisar).
- **Token de AgendaUno como sistema integrado** (`WELLHUB_API_TOKEN`): con él, el negocio
  solo da su Gym ID.
- **Límite de avisos por negocio:** los avisos llegan de las mismas IP de Wellhub para
  todos; el límite de 600 por minuto ahora es de cada negocio.
- La búsqueda de un horario que Wellhub ya tenía usa un minuto de margen.

## Fitpass (sin API)

Fitpass no publica una API. Su integración con software de reservas es privada (Fitune
la tiene por acuerdo con Fitpass). Lo que se puede hacer sin ella:

- **Lugares apartados:** `fitpass` es un canal de reserva más; con su regla de cupo por
  canal, el negocio aparta los «lugares exclusivos» que da a Fitpass y nadie más los toma.
- **Reservas capturadas por recepción** desde Fitpass Admin
  (`POST /sesiones/{sesion}/lugares-plataforma`): con su ID de reservación, su nombre y su
  correo (se puede pegar la tabla). Cada una ocupa su lugar de lo que Fitpass puede tomar;
  la que no cabe se dice por qué. La misma reservación otra vez es la misma.
- **Llegada:** «Llegó» en la lista de la clase o el check-in a mano con el ID de la
  reservación; las dos piden confirmar que se revisó su identificación oficial (lo piden
  los términos de Fitpass). La visita es la de su reserva: no ocupa otro lugar. La
  palabra «folio» se cambió por «ID de la reservación»: no hay folio documentado.
- **Por marcar en Fitpass Admin:** Fitpass acepta cambios de asistencia hasta 48 horas
  después de la clase y de eso depende su pago. `GET /integraciones/fitpass/por-marcar`
  lista quién llegó y quién no de las últimas 48 horas, y `POST
  /lugares-plataforma/{lugar}/marcado` lo da por marcado.
- **Horarios para capturar:** el negocio marca qué clases ofrece en Fitpass y el nombre de
  su disciplina allá (`PUT /integraciones/fitpass/clases`); `GET
  /integraciones/fitpass/horarios` da los de los próximos días con los lugares para
  Fitpass y las canceladas (para quitarlas allá). La web los descarga en CSV.
- Las reservas de Fitpass sin llegada quedan «no asistió» al terminar la clase, aunque el
  negocio no publique en otra plataforma.
- **Con un acuerdo con Fitpass** (el negocio lo pide a Fitpass, como Fitune) se agregaría
  su API: publicar horarios, recibir reservas y mandar la asistencia.

## Conciliación (Wellhub, TotalPass y Fitpass)

- **Excel:** el reporte del portal de Wellhub es un `.xlsx`. Se lee sin dependencias (un
  `.xlsx` es un zip con XML; la imagen del API suma la extensión `zip`), con las fechas de
  Excel (su número de serie).
- **Por persona:** si el reporte trae quién fue (su Wellhub ID, su código o su correo),
  cada pago se empareja con la visita de esa persona ese día; antes solo por el día, y con
  dos visitas el mismo día un pago podía quedar con la visita de otra persona.
- **Inasistencias que se pagan:** si el contrato del negocio con la plataforma dice que
  también paga las reservas que no se cancelaron a tiempo (Fitune lo describe así para
  Fitpass), la tarifa lo marca (`paga_inasistencias`) y esas reservas se esperan y se
  concilian como las visitas.

## Países

Wellhub opera en varios países: dónde se ofrecen Wellhub y TotalPass lo decide el
superadmin (`paises_bienestar` en su configuración, México por omisión). Fitpass sigue
solo en México. Un negocio de otro país no las ve, no las configura y no publica.

## Wellhub y TotalPass: reintentos

Cuando la plataforma no responde, el aviso se reintenta mientras ella espere (15 minutos
una reserva de Wellhub, 5 una de TotalPass, lo que dure el check-in), cada vez más
espaciado (1, 2, 3… hasta 10 minutos), en lugar de rendirse a los cinco intentos.

## Consecuencias

- La validación con las APIs reales sigue pendiente: hacen falta las credenciales de
  socio de cada plataforma y su ambiente de prueba.
- Fitpass: confirmar con un studio Fitpass real cómo se identifica la reserva en
  recepción, si su reporte de pagos trae el ID o el correo, y si paga las inasistencias.
- Queda por confirmar con TotalPass: el host de su admin en producción, los estados de
  una reserva, qué pasa si no se confirma en 5 minutos y si `event.id` del aviso es el
  evento o la ocurrencia.
