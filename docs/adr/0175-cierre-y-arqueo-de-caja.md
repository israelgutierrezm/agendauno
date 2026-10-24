# ADR 0175 — Cierre y arqueo de caja

Estado: Aceptado (2026-10-20). Resuelve dos pendientes del ADR 0166: «cierre de caja
con conteo» y «la anulación como movimiento del día».

## Contexto

El corte de caja (ADR 0023 y 0031) es un reporte que se calcula al consultarlo. No
había apertura ni cierre, fondo, retiros, conteo ni diferencias. Una anulación o una
corrección sobrescribía el cobro y cambiaba días ya pasados.

El dueño eligió (2026-10-20):

- caja por sucursal con turnos;
- apertura con fondo, y retiros e ingresos de efectivo;
- conteo a ciegas con motivo si no cuadra, revisado por el admin;
- lo que se anula, corrige o devuelve después entra como ajuste en la caja abierta.

## Decisión

### Modelo

- **`turnos_caja`**: la caja de una sucursal de su apertura (con fondo) a su cierre.
  - Estados: `abierta`, `contada` (no cuadró; falta el motivo) y `cerrada`.
  - Una sede tiene como mucho una caja sin cerrar: `sucursal_abierta` con índice único
    vale la sede mientras la caja no se cierra.
- **`movimientos_caja`**: el libro de cada turno, con signo y forma de pago.
  - Tipos: `cobro`, `venta`, `propina`, `devolucion`, `anulacion`, `correccion`, `retiro`
    e `ingreso`.
  - Lleva la referencia al pago, a la venta o a la devolución.
- **`conteos_caja`**: al contar, lo esperado, lo contado y la diferencia de cada forma
  de pago. Se registra una sola vez.
- **Formas de pago**: el efectivo se cuenta; la terminal, las transferencias y los
  depósitos en ventanilla se declaran contra su reporte.
  - Esperado de cada una = el fondo (solo efectivo) + lo asentado.
  - Los pagos en línea (Stripe, Mercado Pago, OpenPay) no pasan por la caja.

### Qué se asienta y dónde

| Movimiento | Dónde |
|---|---|
| Cobro de una orden en caja (`liquidar`, `/ordenes/{o}/cobrar` manual o efectivo, el servicio de una cita) | La caja de la sede donde se cobra: la sucursal de la barra (`sucursal_id`), si no la de la orden, si no la única del negocio |
| Venta de mostrador y su propina | La caja de la sede de la venta |
| Devolución en caja, anulación de un cobro, cambio de forma de pago | La caja abierta de la sede donde entró el cobro (o la de su orden), en el momento en que se hace |
| Anulación o cambio de forma de pago de una venta de mostrador | La caja abierta de la sede de la venta, en ese momento |
| Retiro o ingreso de efectivo | La caja donde se registra, con su motivo |

- **Lo cerrado no cambia.** Un ajuste posterior va al libro de la caja abierta del
  momento, con la referencia al original. El cierre conserva lo que se contó.
- **Solo en sedes que usan caja.** En una sede que ya abrió alguna caja, mover dinero
  de mostrador pide su caja abierta; si no la hay, responde 422 `CASH_REGISTER_CLOSED` y
  no queda nada a medias. Una sede que nunca abrió caja cobra como antes.
- **Sin sede.** Si un cobro llega sin sede, con varias sedes y alguna usando caja,
  responde 422 `CASH_REGISTER_INVALID_STATE`: hay que elegir la sucursal en la barra.
- **Concurrencia.**
  - Quien mueve dinero bloquea la caja de la sede dentro de su transacción: después de
    sus propias filas y antes del inventario. Una venta bloquea la caja y luego los
    artículos; así nadie espera en orden cruzado.
  - Contar bloquea la misma fila. Un cobro entra al libro antes del conteo, o espera y
    encuentra la caja contada.
  - Todos bloquean la caja por su clave. Un cobro la busca por la sede abierta sin
    bloquear y después la bloquea por su clave; bloquearla por ese índice se cruzaba con
    el cierre, que lo cambia, y MySQL abortaba una de las dos.
  - `agendauno:verificar-concurrencia` lo prueba en MySQL: ventas del mostrador a la vez
    mientras se cuenta la caja.

### Contar y cerrar

- **A ciegas**: quien solo opera (`caja.operar`) no ve lo esperado ni los cobros del
  turno mientras la caja está abierta; solo sus retiros e ingresos.
- Registra lo contado una vez:
  - Si cuadra, la caja se cierra.
  - Si no cuadra, queda `contada`, ya no recibe dinero y ve la diferencia por forma de
    pago. Cierra con el motivo y el cierre queda para revisión.
- **El admin** (`caja.supervisar`) ve lo esperado en vivo, los cierres con su conteo y
  su libro, y marca revisados los que tuvieron diferencia, con una nota.
- Todo queda en la bitácora: `caja.abierta`, `caja.retiro`, `caja.ingreso`,
  `caja.contada`, `caja.cerrada` y `caja.revisada`.

### Permisos

- `caja.operar` (requiere `sucursales.ver`) y `caja.supervisar` (requiere `caja.operar`),
  en el área de cobros.
- Recepción opera; el admin opera y supervisa.
- Los roles propios que ya cobraban (`ordenes.gestionar` o `pos.vender`) recibieron
  `caja.operar`; los que veían la facturación, también `caja.supervisar` (migración
  000166).

## Consecuencias

- La web tiene la pantalla «Caja» junto al Mostrador. Con quien supervisa, tiene las
  pestañas Caja y Cierres.
- El reporte «Corte de caja» (antes «Caja») sigue igual: movimientos de dinero por
  rango.
- La app móvil no tiene pantalla de caja. Su cobro de citas entra a la caja de la sede
  de la cita y, sin caja abierta, muestra el mensaje del API.
- Pendientes que siguen siendo del dueño: el cobro mixto (dos formas en una venta) y la
  devolución parcial en el mostrador.

## Ajuste (2026-10-23): los cierres pasan a Cobros

- El historial de cierres y su revisión dejan de ser una pestaña de Ventas › Caja y son
  una vista del área de Cobros, «Cierres de caja» (`/cierres-caja`), junto a «Corte de
  caja». Decisión del dueño: lo que se revisa del dinero vive en Cobros; Caja queda
  para la operación del día (abrir, mover efectivo, contar y cerrar).
- La pide `caja.supervisar` (no `facturacion.ver`): quien supervisa la caja sin ver la
  facturación la sigue teniendo. En Caja, quien supervisa ve un enlace a los cierres.
- Un enlace viejo (`/caja?pestana=cierres`) lleva a la vista nueva.
