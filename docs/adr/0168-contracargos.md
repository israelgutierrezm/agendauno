# ADR 0168 — Contracargos

Estado: Aceptado (2026-10-20).

## Contexto

El banco del cliente puede disputar un pago en línea (contracargo). La pasarela lo
avisa por su webhook y le da al negocio unos días para responder con pruebas. Si no
responde, o pierde, la pasarela le quita ese dinero.

Hasta ahora esos avisos se ignoraban: el negocio no se enteraba a tiempo, y en caja y
en reportes el dinero seguía contando como cobrado.

## Decisión

El pago guarda su contracargo: `pagos.contracargo_*`, con el estado (`abierto`,
`ganado` o `perdido`), la referencia de la disputa, el monto, el motivo, el plazo para
responder y cuándo se abrió y se cerró. Lo hace `ContracargosTenant`.

| Estado | Qué pasa |
|---|---|
| Abierto | Se abre una incidencia `contracargo` en «por conciliar», con el monto, el motivo y hasta cuándo responder en la pasarela. El superadmin también se entera. El dinero no cambia todavía. |
| Ganado | El dinero se queda y la incidencia se cierra sola. |
| Perdido | La pasarela devolvió el dinero al cliente. Cuenta como devolución aprobada: corte, movimientos y reportes. El pago queda devuelto total o parcialmente. Lo que se compró no se cancela solo: la incidencia sigue abierta para que el negocio decida. |

Cada cambio queda en la bitácora (`pago.contracargo`). El mismo aviso dos veces no
duplica nada: la devolución es una por disputa.

### Qué avisa cada pasarela

| Pasarela | Avisos | Cómo se ubica el pago |
|---|---|---|
| Stripe | `charge.dispute.created` y `charge.dispute.closed` (`won` o `lost`) | Por el PaymentIntent o, si se guardó la sesión de Checkout, por la sesión que lo generó (consulta a Stripe) |
| Mercado Pago | El pago queda `charged_back`; el aviso `chargebacks` hace releer sus pagos | Por el pago. `in_process` es abierto, `settled` es perdido y `reimbursed` es ganado |
| OpenPay | `chargeback.created`, `chargeback.accepted` (perdido) y `chargeback.rejected` (ganado) | Se relee el cargo en OpenPay |

## Consecuencias

- `GET /pagos` suma `contracargo` (el estado o null) y la lista lo dice.
- La bandeja «por conciliar» nombra el tipo `contracargo`.
- La validación con contracargos reales (sandbox) queda para cuando haya llaves de prueba
  de cada pasarela: se probó con avisos simulados.
