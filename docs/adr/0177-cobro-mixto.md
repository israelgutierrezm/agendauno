# ADR 0177 — Cobro mixto: un cobro en caja con varias formas de pago

Estado: Aceptado (2026-10-20). Resuelve el pendiente «cobro mixto» de los ADR 0166 y
0175. Cambia el ADR 0158 («todo con una forma de pago»).

## Contexto

En caja, un cobro se registraba con una sola forma de pago. En el mostrador y al cobrar
una cita es común que el cliente pague una parte en efectivo y el resto con tarjeta.
Hasta ahora había que registrar dos cobros, o uno con la forma equivocada. Así la caja y
el corte no cuadraban por forma. El dueño lo pidió para V1 (2026-10-20), sobre todo para
TurnoUno.

## Decisión

**Modelo.** El pago de la orden y la venta del mostrador siguen siendo uno solo, con su
total. Un cobro mixto guarda «mixto» como forma (`pagos.metodo` = `mixto`;
`ventas_pos.metodo_pago` y `ordenes.metodo_pago` = `mixto`). Sus partes van en
`partes_cobro`:
- columnas: `pago_id` o `venta_pos_id`, `metodo`, `monto_minor` y `orden`;
- una parte por forma de la caja (efectivo, tarjeta, transferencia o ventanilla);
- entre 2 y 4 partes, sin repetir forma, cada una mayor que cero;
- suman exactamente lo cobrado (`PartesCobro`).

Se descartó guardar varios pagos por orden: anular, devolver, el fulfillment y los
lectores suponen un pago aprobado por orden.

**Dónde se pide.** El campo `partes` (opcional) en:
- `POST /pos/ventas`: suman lo vendido y la propina;
- `POST /ordenes/{orden}/liquidar` y `POST /ordenes/{orden}/cobrar` (solo en caja):
  suman el total de la orden;
- `POST /reservas/{reserva}/cobro`: suman todo. Se reparten en orden: primero el
  servicio (su pago), después los productos y la propina (la venta del mostrador).

Sin `partes`, todo sigue igual. Las partes entran en la huella del intento (R05): la
misma clave con otras partes es otra operación.

**Caja y cambio.**
- Cada parte entra a la caja con su forma: un renglón por forma en el libro (ADR 0175).
- En una venta, lo vendido va primero y la propina después.
- Lo recibido se compara solo con lo que se paga en efectivo, y el cambio sale de ahí.
- El ticket y la respuesta dicen `partes`. El correo del ticket muestra cuánto con cada
  forma.

**Corte y reportes.** El corte cuenta un cobro mixto por cada forma, nunca como
«mixto». Así, una venta mixta reparte lo vendido y la propina entre sus formas. Las
listas muestran «mixto» con sus partes.

**Correcciones.**
- **Cambiar la forma** de un cobro mixto no se puede: se anula y se cobra de nuevo con
  las formas correctas. Para una forma sola sigue igual (ADR 0086).
- **Anular** saca de la caja lo que entró por cada forma.
- **Devolver** un pago mixto pide `metodo`: la forma por la que sale el dinero.
  - Debe ser una de sus partes, y no puede pasar de lo que entró por ella menos lo ya
    devuelto por ella.
  - Queda en `reembolsos.metodo`, y la caja y el corte lo cuentan en esa forma.

## Consecuencias

- **Web:** el mostrador, el cobro de una cita y el registro de pago de una orden tienen
  «Dividir el pago» (`FormasDePago.vue`). El último renglón se completa solo con lo que
  falta. La devolución de un pago mixto pide por qué forma sale.
- **App del equipo:** sigue con una sola forma; el API no cambió para ella.
- **CFDI:** sigue llevando una sola forma de pago, que se elige a mano al timbrar (ADR
  0163). Con un cobro mixto, la guía de llenado del CFDI pide la forma con la que se
  pagó el mayor monto. El negocio debe confirmarlo con su contador; el sistema no la
  elige solo.
- Migración de negocio 000168: crea `partes_cobro` y agrega `reembolsos.metodo`.
