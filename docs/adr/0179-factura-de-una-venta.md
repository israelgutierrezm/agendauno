# ADR 0179 — La factura de una venta

Estado: Aceptado (2026-10-20). Cierra el hueco «la factura no dice qué venta factura»
de la revisión de caja (ADR 0175) y de los candados de los ADR 0086, 0087 y 0089.

## Contexto

`facturas.orden_id` existía, pero `EmitirFacturaTenant` nunca lo llenaba. Eso tenía
tres efectos:

- Anular un cobro o corregir su forma de pago revisan si la venta está facturada
  (ADR 0086/0087), pero nunca encontraban la factura: el candado no funcionaba.
- Quien está acotado a una sede (R19) no veía ninguna factura, porque el alcance se
  resolvía por la orden.
- Nada impedía facturar dos veces la misma venta, y cada factura se capturaba a mano
  desde cero (receptor, conceptos, forma de pago).

Las ventas del mostrador (ADR 0145) no tenían ningún vínculo con su factura.

## Decisión

**La factura dice qué venta factura.**
- Puede ser una orden (`orden_id`: un plan, una cita, una compra) o una venta del
  mostrador (`venta_pos_id`, migración de negocio 000170). Nunca las dos.
- Una factura sin venta sigue siendo posible: es del negocio y de ninguna sede.

**Una venta se factura una vez** (`FacturasDeVentasTenant`).
- Una orden se factura solo si está pagada.
- Una venta del mostrador se factura si no está anulada y tiene productos.
- Ninguna de las dos puede tener ya una factura timbrada.
- Un intento con error no cuenta.
- `EmitirFacturaTenant` bloquea la orden o la venta dentro de la transacción y vuelve a
  revisar. Así dos timbrados a la vez no la facturan dos veces.
- Un anticipo (ADR 0163) no factura la venta, porque la venta todavía no está pagada.
  Por eso `orden_id` y `venta_id` no se aceptan con `anticipo: true`. La factura del
  resto sí se liga a la venta.

**El borrador** (`GET /facturas/borrador?orden=` o `?venta=`).
- Trae lo que se factura:
  - **conceptos:** las líneas de la orden (si suman lo pagado; si no, un concepto con
    el total) o los artículos de la venta con sus extras;
  - **la propina no se factura:** no es ingreso del negocio (ADR 0139).
- **Precios sin IVA.** Los precios de venta ya incluyen IVA, así que cada concepto va
  sin IVA con la tasa del negocio y el CFDI se lo suma. El total puede diferir por
  centavos de redondeo.
- **Claves sugeridas:**
  - servicio: `86121600`/`E48`;
  - artículo: `01010101`/`H87`.
- **Forma de pago del SAT:**
  - efectivo, ventanilla y Oxxo: `01`;
  - SPEI y transferencia: `03`;
  - tarjeta: `04`;
  - mixto (ADR 0177): la forma con la que se pagó más.
- **Receptor:** la persona de la venta. Si ya se le facturó antes, se toman RFC, código
  postal, uso del CFDI y nombre fiscal de su última factura, para no volver a pedirlos.

**Facturada, la venta ya no se anula ni se corrige su forma de pago.**
- Esto aplica a los cobros (ya existía, ahora funciona) y a las ventas del mostrador
  (`CorregirVentaPosTenant`).
- Primero se cancela el CFDI.

**Alcance.**
- Quien está acotado a sedes ve las facturas de las órdenes y de las ventas del
  mostrador de sus sedes.
- Solo factura ventas de sus sedes; si no, 403.

**En pantalla.**
- Cobranza y el mostrador dicen, en cada cobro o venta, «Facturar» (si `facturable`) o
  «Facturada», con un enlace a su factura.
- «Facturar» abre Facturas con el borrador ya puesto y un aviso de qué venta se
  factura.
- Al timbrar, la venta sale de la dirección.

## Consecuencias

- **Lo que hay antes de esta versión:** las facturas siguen sin venta. Ninguna se liga
  hacia atrás, porque el RFC o el monto no bastan para saber de qué venta eran.
- **Cancelar un CFDI** no existe todavía en AgendaUno, y una factura timbrada sigue
  timbrada. Para anular una venta facturada, hoy hay que cancelar el CFDI en el portal
  del proveedor. La venta seguirá bloqueada hasta que exista la cancelación (pendiente).
- **Devolver o reembolsar** una venta facturada sigue siendo posible, igual que antes
  con los reembolsos. La nota de crédito (CFDI de egreso) no se emite desde AgendaUno
  (pendiente).
