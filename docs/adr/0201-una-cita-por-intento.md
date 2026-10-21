# ADR 0201 — Una cita por intento

Estado: Aceptado (2026-10-21).

## Contexto

Agendar una cita no era idempotente: `POST /mi/citas`, `POST /citas` (página pública)
y `POST /agenda/citas` creaban una cita cada vez. Si la respuesta se perdía (red
lenta, más de 10 s en la app) y la persona volvía a intentar, o si tocaba dos veces:

- Con «cualquier profesional» (ADR 0062), el segundo intento ya no encontraba libre
  al primer profesional y se asignaba a otro. El cliente quedaba con dos citas y dos
  órdenes.
- Con un profesional elegido, el segundo intento respondía «Ese horario ya no está
  disponible». La cita sí se había agendado, pero se anunciaba como fallida.

Las clases ya tenían su `idempotency_key` (`ReservasTenant::crear`) y el cobro de una
cita su `clave_intento` (`POST /reservas/{reserva}/cobro`).

## Decisión

- Las tres rutas aceptan `idempotency_key`: hasta 64 letras, números, `-` o `_`.
  `AgendarCitaTenant` la guarda en la reserva (`reservas.idempotency_key`, ya única)
  **en la misma transacción** que crea la sesión, la orden y la retención.
- **Antes de agendar**, si ya hay una reserva con esa clave, se devuelve. En la
  carrera, si dos intentos llegan a la vez, el segundo encuentra la cita del primero
  por una de dos vías:
  - el candado del profesional (le responde «ya no está disponible»);
  - el índice único, que deshace todo lo que ese segundo intento había creado.
- Es un reintento **solo si** la persona, el servicio, la sede, la hora y, si se
  eligió, el profesional coinciden. Si no, se rechaza con 422 («Esta solicitud ya se
  usó para otra cita»): nunca se devuelve la cita de otra persona ni una distinta de
  la que se pidió.
- Respuestas: una cita nueva responde 201; la de un reintento, 200 con los mismos
  datos. En la página pública, el reintento no vuelve a registrar la atribución
  (ADR 0142).
- Los clientes generan la clave por intento: la misma mientras repiten exactamente lo
  mismo, otra en cuanto cambia lo que se pide y otra después de una respuesta.
  - Web: `intentoUnico()` en `lib/intento.ts`.
  - App: `IntentoUnico` en `lib/core/intento.dart`, dentro de los repositorios de
    la cuenta y de la agenda.

## Consecuencias

- Un cliente con una versión anterior (sin clave) funciona igual que antes.
- La clave no protege dos solicitudes distintas: dos citas a dos horas son dos citas.
  Lo que las limita sigue siendo `citas.maximo_por_pagar` (ADR 0065).
