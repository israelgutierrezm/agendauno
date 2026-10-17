# ADR 0158 — Cobrar al terminar la cita: servicio, productos y propina juntos

Estado: Aceptado (2026-10-17). Continúa el ADR 0139 (propina en el mostrador), que
dejaba pendiente la propina de un servicio cobrado en caja.

## Contexto

Al terminar una cita, recepción cobraba el servicio en el detalle de la cita
(`POST /ordenes/{orden}/liquidar`). Si el cliente se llevaba un producto o dejaba
propina, hacía falta otro cobro en el Mostrador. Eso daba dos cobros, dos comprobantes
y el cambio calculado por separado.

## Decisión

- `POST /reservas/{reserva}/cobro` (solo citas, `ordenes.gestionar`) cobra en un paso:
  - el servicio, si su orden sigue pendiente;
  - los productos (`items`, como en el Mostrador);
  - la propina;
  - todo con una forma de pago y, en efectivo, lo recibido.
- **Todo o nada** (`CobroDeCitaTenant`): la orden se liquida y la venta se registra en
  la misma transacción, con la reserva y la orden bloqueadas. Si falta stock o lo
  recibido no alcanza para todo, no se cobra nada.
- **Dónde queda cada peso**:
  - El servicio es el pago de su orden: corte, reportes, facturación y correcciones
    (ADR 0086/0087), como siempre.
  - Los productos y la propina son una venta del mostrador ligada a esa orden
    (`ventas_pos.orden_id`). La venta guarda cuánto fue del servicio
    (`servicio_minor`) solo para el ticket y el cambio.
  - Los reportes de ventas y el corte siguen sumando `total_minor` y
    `propina_minor`, así que el servicio no se cuenta dos veces.
- **Comprobante**:
  - La respuesta trae servicio, productos, propina, total, recibido y cambio.
  - Si hubo productos o propina, también el ticket de la venta: el servicio va primero
    y se puede mandar por correo.
- **Idempotencia**: la clave del intento es la de la venta. Un reintento devuelve el
  mismo cobro y la orden no se cobra dos veces (`liquidar` ya lo era).
- **Permisos**: vender productos en este cobro pide además `pos.vender` y la función
  `inventario` del plan. La propina no.
- Una cita ya cobrada admite cobrar después solo productos o propina; sin nada que
  cobrar responde 422.
- La web: «Registrar pago» en el detalle de la cita abre este cobro, con los
  productos, la propina (sin, 10 %, 15 %, 20 % u otra), la forma de pago, lo recibido y
  su cambio. Al terminar muestra el comprobante.

## Consecuencias

- Migración de negocio 000148.
- Anular la venta del mostrador no anula el pago del servicio, y al revés: son dos
  cobros con sus propias correcciones.
- Pendiente: anticipo y saldo pendiente de una cita.
