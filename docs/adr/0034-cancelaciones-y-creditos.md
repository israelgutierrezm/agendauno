# ADR 0034 — Cancelaciones, asistencia y créditos que se explican solos (fase 1, punto 1.4)

Estado: Aceptado (2026-09-26). Complementa el R7 (lista de espera) y el R8 (política
congelada en la reserva).

## Contexto

- **Quién cancela:** no se registraba. Recepción y el cliente usaban la misma
  política, así que el negocio que cancelaba un lugar le cobraba el crédito al
  cliente si era tarde. El aviso decía "Como cancelaste…" aunque hubiera cancelado
  el negocio.
- **Asistencia:** se validaba fuera del candado. Una cancelación simultánea podía
  colarse, y una reserva con asistencia se podía cancelar.
- **Corregir asistencia:** pasar de "no vino" a "llegó" no movía el crédito. La
  asistencia y el saldo podían contradecirse.
- **Historial:** el alumno no veía sus movimientos, y el equipo solo veía textos
  internos ("Confirmacion de retencion").
- **Antes de confirmar:** no había forma de saber qué pasaría con el crédito.

## Decisiones

- **Quién canceló** (`reservas.cancelada_por`: cliente / negocio / sistema, más el
  usuario y la hora). La política depende de eso:
  - Solo la cancelación del **cliente** (él mismo, o recepción a petición suya)
    puede cobrar el crédito por ser tardía.
  - Si cancela el **negocio**, el crédito siempre regresa. Es lo que hace recepción
    por defecto (`por=negocio`); con `por=cliente` aplica la política del cliente.
  - Las bajas de persona cancelan como negocio. Una cita que vence sin pago la
    cancela el sistema.
- **Vista previa = lo que pasa**: la misma decisión (`EfectoCancelacion`) alimenta la
  vista previa y la cancelación real.
  - Endpoints: `GET /reservas/{r}/cancelacion?por=…`,
    `GET /mi/reservas/{r}/cancelacion` y `GET /sesiones/{s}/cancelacion`.
  - Mensajes: "Se devolverá 1 crédito.", "Se cobrará 1 crédito: se cancela con menos
    de 6 h de anticipación.", "Se cancelarán 2 reservas y se devolverán 2 créditos."
  - Si la reserva ya está pagada, avisa que ese dinero no se reembolsa solo.
- **Transiciones que no se contradicen**:
  - Cancelar dos veces no hace nada la segunda.
  - Una reserva con asistencia no se cancela, ni una clase que ya tiene asistencia
    (409 `RESERVATION_ATTENDED`).
  - No se marca asistencia en una reserva cancelada, en espera o por pagar; el
    mensaje dice por qué.
  - La asistencia se registra bajo el candado de la reserva.
- **Corregir asistencia compensa**:
  - Re-marcar lo mismo no hace nada; tampoco duplica el evento de lealtad.
  - Si la corrección cambia si se cobra o no, se asienta un consumo o un reverso
    "Corrección de asistencia".
- **Historial en palabras**: `concepto` ("Asistencia", "No asistió", "Cancelación
  tardía", "Créditos vencidos", "Corrección de asistencia"…) y la clase de origen.
  - Lo ve el alumno en `GET /mi/derechos/{d}/movimientos`, solo el suyo (404 si no
    es suyo).
  - El equipo lo ve en la ficha.

## Consecuencias

- Recepción debe elegir "Lo pidió el cliente" para cobrar una cancelación tardía;
  por defecto no se penaliza.
- `tolerancia_no_show` (strikes antes de sancionar) sigue sin aplicarse: es una regla
  nueva, no una corrección, y queda pendiente.
- Reembolsar el dinero de una reserva pagada que cancela el negocio sigue siendo
  manual (desde Cobranza); la vista previa lo advierte.
