# ADR 0125 — Migración guiada: cargas que se corrigen por partes y saldos de apertura

Estado: Aceptado (2026-10-11). Plan de mejoras de captación, C07 (bloque 15).

## Contexto

Ya existían importaciones por CSV de alumnos, instructores y clases. Las dos primeras
eran todo o nada. Un correo que ya estaba en el negocio era un error, así que subir
otra vez el archivo corregido fallaba por las filas que sí habían entrado. Además, la
importación de instructores mandaba la invitación a todos sin preguntar. Y no había
forma de traer el saldo con el que cada alumno llegaba de otro sistema: solo vendiendo
el plan otra vez, lo que inventa una venta, o con ajustes manuales uno por uno.

El plan pide varias cosas:

- un resumen de aceptados, rechazados y conflictos;
- corregir filas sin repetir toda la carga;
- saldos y vigencias que coincidan con el corte acordado, sin inventar pagos
  históricos;
- no enviar invitaciones masivas sin confirmación;
- medir el tiempo y los errores de la incorporación.

## Decisión

**Estados por fila** (alumnos, instructores y saldos):

- `valida`: la fila está lista para cargarse.
- `invalida`: sus datos no sirven.
- `existente`: ya está en el negocio. Un alumno o instructor con ese correo y ese
  nombre, o un saldo con el mismo corte, persona y plan. Repetir el archivo no lo
  duplica.
- `conflicto`: el correo o el celular son de otra persona, la fila viene repetida en el
  archivo, o el alumno ya tiene ese plan vigente aquí.

El resumen trae `validas`, `existentes`, `conflictos`, `rechazadas` e `invalidas`
(rechazadas + conflictos). `invalidas` es lo que impide cargar todo de una vez.

**Carga por partes:** sin `parcial` sigue siendo todo o nada. Con `parcial`, la web lo
ofrece y pide confirmación: se cargan solo las válidas. El archivo corregido se vuelve a
subir y lo ya cargado queda `existente`.

**Alumnos:** se agregan las columnas `celular`, `fecha_nacimiento` y `genero`, con las
mismas reglas que el alta individual (el celular se compara como número).

**Instructores:** `invitar` es obligatorio. Con `0` se crean las cuentas sin activar y
se invita después desde Equipo. Con `1` (la web lo confirma con el número de correos) se
envía la invitación. El cupo del plan cuenta solo a los nuevos.

**Saldos de apertura:** `POST /importaciones/saldos` con `corte`, `evidencia` (de dónde
salen los saldos) y un CSV con `email`, `plan`, `creditos`, `vence` e `inicio`. Pide
gestionar planes y créditos. Por fila:

- Crea el plan con `origen = apertura` y `referencia_apertura` (huella de corte, persona
  y plan), sin orden ni pago.
- Crea el derecho con su vigencia: un pack vale hasta `vence`. En un plan recurrente, el
  ciclo en curso termina en `vence` y el siguiente se cobra al día siguiente.
- Registra en el libro de créditos una concesión con origen `apertura`, el corte, la
  evidencia y la fila. El estado de cuenta la muestra como «Saldo de apertura».
- Rechaza la fila si el alumno no existe, si el plan no existe o es ambiguo, si son
  clases extra, si ya había vencido al corte o si no trae créditos.

**Reservas futuras:** no se copian. Después del corte, las clases se reservan aquí. La
pantalla lo dice junto con los pasos: alumnos, planes con el mismo nombre y un corte
acordado para no operar saldos en dos sistemas.

**Medición:** cada carga va a la bitácora (`importacion.miembros|instructores|saldos`)
con su resumen, lo creado y su duración. La de saldos lleva la evidencia como motivo.

## Consecuencias

- Una migración se hace por partes sin duplicar a nadie. El dueño ve qué entró, qué ya
  estaba y qué falta corregir.
- Los reportes de ventas no cuentan saldos de apertura: no hay orden. Un alumno con saldo
  de apertura ya no es «primera vez» ni paga la inscripción como nuevo.
- Las clases ya evitaban duplicar sesiones al repetir el archivo; no cambian.
- No se promete migración ilimitada: el tope de filas por archivo sigue siendo el
  parámetro de la plataforma (ADR 0047).
