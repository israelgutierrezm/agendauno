# ADR 0178 — Devolución parcial en el mostrador

Estado: Aceptado (2026-10-20). Resuelve el pendiente «devolución parcial en el
mostrador» de los ADR 0166 y 0175.

## Contexto

Una venta del mostrador solo se podía anular completa (ADR 0089). Si el cliente
regresaba una de tres piezas, había que anular todo y volver a vender dos. Eso dejaba
la caja, el corte y el inventario con movimientos que no pasaron. Las devoluciones de
pagos (`reembolsos`) son de una orden y no aplican a una venta del mostrador. El dueño
la pidió para V1 (2026-10-20).

## Decisión

**Modelo.**
- `devoluciones_venta`: la venta, el monto, la forma por la que salió, el motivo, quién
  y una llave única por venta.
- `lineas_devolucion_venta`: cuántas piezas de cada línea y su monto.
- `lineas_venta_pos.cantidad_devuelta`: lo ya devuelto de cada línea.
- Migración de negocio 000169.

**Reglas** (`DevolverVentaPosTenant`; `POST /pos/ventas/{venta}/devoluciones`, con
`pagos.reembolsar`):
- **Cuánto:** cada pieza vale lo que se cobró por ella ese día: su precio con extras,
  que es exacto porque el subtotal es precio × piezas. La propina no se devuelve aquí.
- **Límite:** no se devuelve más de lo que queda por devolver de cada línea.
- **Plazo:** lo fija el parámetro del negocio `pos.dias_para_devolver` (30 días por
  omisión; 0 = sin límite). Una venta anulada no se devuelve.
- **Forma de salida:** la de la venta. Si fue mixta (ADR 0177), una de sus formas,
  elegida al devolver, sin pasar de lo que entró por ella para lo vendido menos lo ya
  devuelto por ella.
- **Caja:** el dinero sale de la caja abierta de la sede en ese momento (ADR 0175),
  como movimiento `devolucion`. Lo cerrado no cambia.
- **Inventario:** las piezas vuelven a la sede donde se vendieron.
- **Concurrencia:** se bloquea la venta, después la caja y al final los artículos (por
  id), en el mismo orden que al vender. `agendauno:verificar-concurrencia` prueba que
  varias devoluciones a la vez de la misma venta no devuelven de más.
- **Idempotente:** la misma `Idempotency-Key` devuelve la misma devolución.
- **Bitácora:** queda en `pos.venta_devuelta`.

**Con devoluciones, la venta ya no se anula ni se corrige su forma:** se devuelve lo
que queda. Así no se devuelve dos veces el mismo dinero.

**Corte y reportes.**
- El corte lista la devolución como `devolucion`, en su fecha y por su forma. Cuenta en
  `devuelto_minor` y en el total de su forma.
- El resumen del mostrador da lo vendido sin lo devuelto, más `devuelto_minor`.
- Las tendencias cuentan las piezas y el dinero de cada artículo sin lo devuelto.
- El resumen diario lo cuenta como devuelto en su fecha.

## Consecuencias

- En la web, la lista de ventas del mostrador tiene «Devolver» (`DevolverVenta.vue`):
  las piezas por línea, el motivo y, si fue mixta, la forma. Cada venta dice cuánto se
  devolvió.
- El ticket sigue siendo el de la venta. La devolución queda en el corte, en la caja y
  en la bitácora.
- No se emite un CFDI de egreso por la devolución; el negocio lo hace en su sistema de
  facturación si la venta estaba facturada.
