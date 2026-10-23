# ADR 0176 — Importar las reservas futuras de clases

Estado: Aceptado (2026-10-20). Completa el ADR 0125 (migración guiada y saldos de
apertura), que dejaba las reservas futuras fuera, y sigue el patrón del ADR 0160
(importar citas futuras).

## Contexto

Un estudio que cambia de plataforma ya podía traer a sus alumnos, sus saldos de apertura
y su horario de clases. Las reservas que sus alumnos ya tenían hechas no se podían
traer: había que hacerlas de nuevo aquí después del corte. Para un estudio con decenas de
reservas por semana, eso frena el cambio.

El dueño decidió (2026-10-20) que entre en V1, porque se captarán estudios que ya usan
otra plataforma. Lo que pidió:

- importar las reservas futuras de clases, confirmadas y en lista de espera;
- reconocer al alumno, la clase, el horario y la sucursal;
- una regla explícita para no descontar créditos dos veces;
- un resumen de lo migrado, lo rechazado y lo pendiente.

Eligió la regla: cada reserva aparta su crédito aquí como cualquier reserva. Si los
saldos ya la descontaron, se reintegra el crédito y se aparta, y el saldo neto no cambia.

## Decisión

**Endpoints** (negocios de clases, `reservas.gestionar`):

- `GET /importaciones/reservas/plantilla`: el CSV con las columnas `referencia`, `email`,
  `clase`, `sucursal`, `fecha`, `hora`, `estado` y `posicion`.
- `POST /importaciones/reservas/preview` con `archivo`, `corte` y `ya_descontadas`:
  corre todas las reglas y revierte. Devuelve las filas, el resumen y una `confirmacion`
  que vale 30 minutos para ese archivo, ese corte y esa opción.
- `POST /importaciones/reservas` con lo mismo, la `confirmacion` y `parcial`.

**Correspondencia**:

- El alumno se reconoce por su correo y tiene que existir; las fichas archivadas no
  cuentan.
- La clase se busca por su sede, su fecha y hora local (en la zona de la sede) y su
  nombre. Vale el nombre de la clase o el de su actividad, sin importar mayúsculas ni
  acentos.
- Sin sede, se usa la única del negocio. Solo cuentan las sedes que ve quien importa.
- Si a esa hora hay dos clases con ese nombre de actividad, se pide el nombre de la clase.

**Corte**: solo se importan clases desde el día del corte (en la zona de la sede) que
todavía no empiezan. Lo anterior queda en el otro sistema.

**Cada reserva entra como una hecha aquí** (`ReservasTenant::crear`):

- aparta su crédito;
- respeta el cupo;
- la lista de espera queda en orden: primero las confirmadas y luego las de espera por su
  `posicion`;
- congela la política de cancelación de hoy.

Cancelar a tiempo devuelve el crédito sin pasos aparte. La clave de idempotencia
`importacion:<huella>` (de la referencia o de alumno y clase) evita duplicar una reserva
al volver a subir el archivo.

**Sin descontar dos veces** (`ya_descontadas`, obligatorio):

- **No**: los saldos de apertura incluyen estas reservas. Cada reserva confirmada aparta
  su crédito del saldo cargado.
- **Sí**: el otro sistema ya descontó las reservas de los saldos. Por cada reserva
  confirmada se registra una concesión de 1 crédito con origen `apertura` («reserva
  importada: su crédito ya se había descontado») y luego se aparta. El saldo neto no
  cambia y el libro muestra las dos cosas.
  - El crédito vuelve al plan que va a cubrir la reserva: el mismo que se usaría al
    reservar. Si ningún plan tiene saldo, vuelve al primero que la cubriría
    (`ResolverDerechoTenant::paraReintegro`).
  - Un plan ilimitado no gasta créditos, así que no se reintegra nada.
  - Como reintegrar es conceder créditos, también pide `creditos.gestionar`.
- La lista de espera no aparta crédito en ningún sistema y nunca se reintegra. Si aquí
  hay lugar, la reserva queda confirmada y aparta su crédito; la fila lo avisa.

**Estado de cada fila**:

| Estado | Cuándo |
|---|---|
| `valida` | Entra |
| `existente` | Ya se importó o el alumno ya tenía esa clase: no se duplica |
| `pendiente` | Los datos sirven, pero no tiene un plan con saldo o la clase está llena aquí: lo decide el negocio |
| `invalida` | Los datos no sirven: no hay alumno con ese correo, la clase no existe, ya pasó o es antes del corte |

El resumen trae `total`, `validas`, `existentes`, `pendientes`, `invalidas`, `en_espera`
y `creditos_reintegrados`. Sin `parcial`, la importación es todo o nada. Con `parcial`
entran las válidas. El archivo corregido se vuelve a subir y lo ya importado queda
`existente`.

**Sin avisos**: como con las citas (ADR 0160), sus eventos quedan publicados sin
consumidores y la reserva lleva `sin_avisos`, así que tampoco recibe recordatorios.
Cada carga queda en la bitácora (`importacion.reservas`) con su resumen, el corte y la
opción elegida.

## Consecuencias

- La migración guiada queda completa para un estudio de clases: alumnos, saldos de
  apertura, horario y reservas futuras, todo con un corte.
- La pantalla «Importar reservas» está junto a la de saldos. Explica la regla y pide
  elegir la opción antes de revisar el archivo.
- No se importa el historial de clases pasadas ni de pagos. El historial sigue en el
  otro sistema y el dinero entra como saldo de apertura (ADR 0125).

## Ajuste (2026-10-23): no avisar al importar ≠ sin recordatorios

- Importar nunca avisa: los eventos de cada reserva importada quedan publicados sin
  consumidores (ni correo, ni push, ni webhooks).
- Los recordatorios antes de la clase son otra opción, «Mandarles su recordatorio antes
  de cada clase» (`recordatorios`, apagada por omisión, como en la importación de citas
  de la ADR 0160). Encendida, la reserva queda con `sin_avisos = false` y el generador
  de recordatorios la toma como a cualquier otra. La confirmación del archivo incluye la
  opción y la bitácora la registra.
