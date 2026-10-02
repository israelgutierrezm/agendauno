# ADR 0087 — Anular un cobro en caja registrado por error

Estado: Aceptado (2026-10-01). Sigue al ADR 0086.

## Contexto

Recepción a veces registra un cobro que no ocurrió: se cobró dos veces, se pulsó
«Cobrar» y el cliente aún no pagaba, o se cobró a la persona equivocada.

Una **devolución** no es la respuesta: dice que el dinero entró y salió, deja la
orden pagada y reembolsada (cancelada), y ya no se puede cobrar bien.

Lo correcto es decir que el cobro nunca ocurrió. Pero cobrar tiene efectos (el
cumplimiento de la orden, `FulfillmentTenant`) que hay que deshacer:

- **Cita o reserva:** confirma la reserva.
- **Renovación:** recorre el ciclo de la membresía y cierra la cobranza.
- **Venta de un plan o paquete:** crea acuerdos con derechos (créditos) en el ledger.
- **`orden.pagada` (outbox):** recibo, aviso al equipo, puntos de lealtad, webhooks y
  automatizaciones.

## Decisión

### `AnularCobroTenant` (`POST /pagos/{pago}/anular`)

- Exige el permiso `pagos.reembolsar` y un motivo.
- El pago pasa a **`anulado`** (estado nuevo; la columna es texto, sin migración).
- La orden vuelve a **`pendiente`**: queda por cobrar y se puede cobrar otra vez.
- Lo que el cobro concedió se retira:
  - **Cita o reserva:** la reserva sigue confirmada; solo vuelve a estar por cobrar.
  - **Plan o paquete:** cada derecho vuelve a 0 con un movimiento `reverso` en el
    ledger (origen nuevo `anulacion`). El acuerdo se cancela, sin pago automático ni
    renovación pendiente. Es lo mismo que hace la devolución total, ahora compartido
    en `DerechosDeOrdenTenant`.
- Se emite **`pago.anulado`** al outbox y queda en la bitácora: estados antes y
  después, monto y motivo.
- Todo ocurre en una transacción, con candado sobre el pago, la orden y cada derecho.

### Cuándo procede

- El negocio lo permite: `pagos.permitir_anular_cobro`, **apagado de inicio**.
- Está dentro del plazo: `pagos.horas_para_anular`, 24 h por defecto, 0 = sin
  límite.
- Es un cobro en caja (`proveedor = manual`); un pago en línea se devuelve.
- Está aprobado y sin devoluciones.
- No es de una **renovación**: su efecto es el ciclo de la membresía, y para eso
  existe la devolución.
- La venta **no está facturada**: primero se cancela el CFDI.
- Los créditos o la membresía **no se han usado**: sin consumos ni retenciones
  activas. Si ya se usaron, se devuelve.

La agenda (por cita) y Cobros › Movimientos (por pago) mandan `anulable`; la
interfaz ofrece la acción solo entonces. La factura y el uso de créditos se validan
al anular, para no consultarlos por cada fila.

### Lo asíncrono (outbox)

El relay procesa en orden, pero un evento que falla se reintenta después. Por eso:

- **Puntos:**
  - Con `pago.anulado` se retiran los puntos de esa compra (`retirarDeCompra`), sin
    dejar el saldo en negativo si ya se gastaron.
  - Con `orden.pagada` solo se suman si la orden sigue pagada y si esa compra no dejó
    ya puntos vigentes. Así, un evento que llega tarde no premia un cobro anulado ni
    paga dos veces una orden que se volvió a cobrar.
- **Recibo y aviso al equipo:** `orden.pagada` no se envía si la orden ya no está
  pagada.
- **Webhooks y automatizaciones:** reciben `pago.anulado` (está en el catálogo de
  eventos).

### Interfaz

`CorregirCobro.vue` reúne las dos correcciones de un cobro en caja: la forma de pago
(ADR 0086) y anularlo, con su motivo y su confirmación. Está en el detalle de la cita
y en Cobros › Movimientos. Ahí el corte de caja ya no cuenta el cobro anulado (solo
suma pagos aprobados o reembolsados).

## Consecuencias

- Un cobro registrado por error se deshace sin tocar la base de datos, con rastro
  completo, y la venta puede cobrarse bien.
- No se anulan: pagos en línea, renovaciones, ventas facturadas, créditos ya usados y
  ventas del punto de venta (tabla propia). Para esos queda la devolución.
- El recibo que el cliente ya recibió no se retira. Si el negocio lo necesita, puede
  avisarle con una plantilla del evento `pago.anulado`.
