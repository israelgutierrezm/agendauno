# ADR 0030 — Pagos que llegan tarde o dos veces (fase 1, punto 1.2)

Estado: Aceptado (2026-09-25). Usa la bandeja "por conciliar" del ADR 0029.

## Contexto

Un cliente empieza a pagar su cita, vence su apartado (30 min) y la confirmación del
pago llega después. El fulfillment sobrescribía la orden cancelada a "pagada" y
emitía la venta, pero la reserva seguía cancelada: dinero cobrado sin cita, sin
aviso ni registro. Los enlaces de pago vivían más que el apartado (Mercado Pago y
OpenPay, 3 días). Un cobro doble solo quedaba en el log.

## Decisiones

- **"Pagado" no es "confirmado"**: el dinero que llega queda siempre registrado (pago
  aprobado), y una orden cancelada nunca pasa a pagada sin revalidar.
- **Al vencer el apartado** se guarda por qué (`reservas.motivo_cancelacion =
  vencio_pago`) y se cierra el cobro abierto en la pasarela, para que no pueda
  pagarse tarde. Si la pasarela dice que ya se cobró, se atiende al llegar.
- **Pago tardío** (política elegida por el negocio): si la reserva venció por falta
  de pago, su horario sigue libre y aún no empieza, **se reconfirma** con ese pago,
  revalidando bajo candado (en una cita, el del profesional y sus choques; en una
  clase, el cupo). Nunca desplaza a otro cliente. Si no se puede, la orden sigue
  cancelada, el pago queda **por conciliar** (`pago_tardio`) y se avisa al cliente
  (qué pasó y que el negocio lo contactará) y al equipo que ve la facturación.
- **Cobro doble**: un segundo cobro de una compra ya pagada queda por conciliar
  (`pago_duplicado`) y avisa al equipo.
- Desde la bandeja, un pago tardío o doble se **devuelve** (reembolso completo con la
  llave de la incidencia: repetir no devuelve dos veces) o se marca resuelto (p. ej.
  se reagendó con el cliente).
- Un aviso repetido de un pago ya aprobado o devuelto no lo reabre.

## Consecuencias

- Una reserva cancelada por el propio cliente no se reconfirma aunque su pago llegue
  después: queda por conciliar.
- El punto 1.4 ampliará `motivo_cancelacion` para distinguir quién canceló.
