# ADR 0089 — Corregir y anular ventas de mostrador

Estado: Aceptado (2026-10-02). Extiende los ADR 0086 y 0087 al punto de venta.

## Contexto

Los cobros en caja ya se pueden corregir (forma de pago, ADR 0086) y anular (ADR
0087). Las ventas de mostrador (pomadas, aceites, ropa) viven aparte, en
`ventas_pos`: no son órdenes ni tienen pagos. Si se registraban con la forma de pago
equivocada o por error (dos veces, o el cliente se arrepintió antes de pagar), no
había forma de corregirlas. El corte de caja y el inventario quedaban mal.

Además, la lista de ventas mostraba la forma de pago con su valor interno
(«efectivo»), no con su nombre.

## Decisión

`CorregirVentaPosTenant`, con las mismas reglas que un cobro en caja:

- **Forma de pago** (`PUT /pos/ventas/{venta}/metodo`, permiso `pos.vender`): solo si
  el negocio lo permite (`pagos.permitir_corregir_metodo`) y dentro del plazo
  (`pagos.horas_para_corregir`). El total no cambia.
- **Anular** (`POST /pos/ventas/{venta}/anular`, permiso `pagos.reembolsar`, con
  motivo): solo si el negocio lo permite (`pagos.permitir_anular_cobro`, apagado de
  inicio) y dentro del plazo (`pagos.horas_para_anular`).
  - La venta queda con `anulada_en`, quién y el motivo (columnas nuevas, sin estado
    aparte).
  - No cuenta en el corte ni en los movimientos.
  - Lo vendido regresa al inventario de su sede como una entrada «Venta anulada»,
    ligada a la venta.

Las dos quedan en la bitácora (`pos.metodo_corregido`, `pos.venta_anulada`). Usan los
mismos errores que un cobro (`PAYMENT_NOT_CORRECTABLE`, `PAYMENT_NOT_VOIDABLE`).

En la web, la lista de ventas del mostrador:

- muestra la forma de pago con su nombre y las anuladas con su estado;
- usa el mismo componente de corrección que los cobros (`CorregirCobro`), con las
  rutas de la venta y los textos de una venta.

## Consecuencias

- Un error en el mostrador se corrige igual que uno en caja, con las mismas reglas
  configurables por el negocio.
- El inventario no se descuadra por ventas que no ocurrieron.
- Una venta anulada se conserva para la bitácora: no se borra.
