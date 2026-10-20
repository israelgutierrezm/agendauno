# ADR 0180 — Cancelar, sustituir y notas de crédito

Estado: Aceptado (2026-10-20). Cierra los dos pendientes del ADR 0179.

## Contexto

Una factura timbrada no se podía cancelar desde AgendaUno. Al anular una venta
facturada, el sistema pedía cancelar antes el CFDI, pero eso solo se podía hacer en el
portal del proveedor. Además, la factura seguía timbrada en AgendaUno, así que la venta
quedaba bloqueada para siempre.

Tampoco había notas de crédito: devolver o reembolsar una venta facturada dejaba la
factura por el total.

## Decisión

**Una factura es de ingreso o una nota de crédito** (migración de negocio 000171):
- `tipo`: `I` (ingreso) o `E` (egreso).
- `relacionada_id` y `relacion`: con qué factura se relaciona y cómo.
  - Una nota de crédito usa `01` con la factura que corrige.
  - Una sustituta usa `04` con la que sustituye.
- Se guardan el régimen del receptor, la forma de pago y los conceptos con que se
  timbró. La nota de crédito y la sustituta los necesitan.
- Las facturas de antes de esta versión no tienen nada de esto. Su sustituta toma los
  conceptos de su venta, si tiene; su nota de crédito pide el régimen del cliente.
- Las notas de crédito llevan la venta de su factura, para que se vean por sede. No
  cuentan como «la factura de la venta».

**Cancelar** (`POST /facturas/{factura}/cancelar`, con `pagos.reembolsar`, como anular
o devolver un cobro):
- **Motivos:**
  - `02`: con errores y sin sustituta;
  - `03`: la venta no se llevó a cabo;
  - `01`: solo se pide al sustituir;
  - `04` (factura global) no aplica: AgendaUno no emite facturas globales.
- **No se cancela** lo que el SAT no dejaría:
  - una factura con notas de crédito vigentes;
  - un anticipo ya aplicado (primero se cancela la factura que lo aplica);
  - una factura cuya cancelación ya está en espera.
- **Resultado del pedido:**
  - **Aceptada:** la factura queda cancelada. Su venta se puede volver a facturar,
    anular o corregir. Los anticipos que aplicaba quedan otra vez por aplicar. Sale
    `factura.cancelada` al outbox.
  - **En espera:** el cliente debe aceptarla, o el SAT la está verificando. La factura
    sigue timbrada y su venta sigue facturada.
- **Conciliación:** `agendauno:conciliar-cancelaciones-cfdi` corre cada 30 minutos y
  pregunta cómo van las cancelaciones en espera:
  - aceptada: la factura queda cancelada;
  - rechazada o vencida: la factura sigue vigente, se dice por qué y se puede volver a
    pedir.
- **Concurrencia:** se bloquea la factura y se revisa dentro de la transacción.
- **Bitácora:** queda en `factura.cancelacion_pedida`.

**Sustituir** (`POST /facturas` con `sustituye`, y `GET /facturas/borrador?sustituye=`):
- Para corregir datos mal. El borrador trae lo timbrado.
- La sustituta toma la venta de la anterior y se relaciona con ella (`04`).
- Ya timbrada la sustituta, se pide cancelar la anterior con el motivo `01`.
- Si el proveedor no la acepta en ese momento, la nueva queda timbrada y se avisa. La
  anterior se puede volver a cancelar desde la lista.
- Pide también `pagos.reembolsar`, porque sustituir cancela.
- No se sustituye una factura que aplica anticipos (se cancela y se factura de nuevo el
  resto) ni una con notas de crédito.

**Nota de crédito** (`POST /facturas/{factura}/notas-credito`, con
`pagos.reembolsar`):
- Es un CFDI de egreso por lo devuelto, descontado o bonificado, con IVA.
- Va al mismo receptor, con uso `G02`, la clave `84111506`/`ACT`, la forma en que se
  devolvió el dinero y la relación `01` con la factura.
- No pasa de lo que queda por acreditar: el total de la factura menos sus notas
  vigentes.
- Gasta un timbre, como cualquier factura.
- Un anticipo no se acredita: se aplica al facturar el resto (ADR 0163).
- Si la factura no guardó el régimen del cliente, se pide, y queda guardado en ella.
- Nunca se emite sola al devolver o reembolsar. Es un documento fiscal y gasta un
  timbre, así que la decide quien opera.

**En pantalla:**
- Facturas dice si cada una está cancelada, con su cancelación en espera o rechazada,
  y si es nota de crédito o sustituta, con el folio de su relacionada.
- Cada factura ofrece «Nota de crédito» (si queda por acreditar) y «Cancelar». El
  diálogo de cancelar ofrece «Sustitúyela» si hay datos mal.
- Hay un filtro de canceladas.

## Consecuencias

- **FacturAPI:**
  - `DELETE /invoices/{id}?motive=` (con `substitution` en el `01`) y
    `GET /invoices/{id}` para conciliar.
  - `status: canceled` o `cancellation_status: accepted` es aceptada. `pending` y
    `verifying` son en espera. `rejected` es rechazada. `expired` o `none` es vencida.
  - Todo esto se probó con dobles: la prueba en vivo queda pendiente de llaves reales,
    igual que el timbrado.
- **Una cancelación aceptada no se deshace.** Para volver a facturar se emite otra
  factura de la venta.
