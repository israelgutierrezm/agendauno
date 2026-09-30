# ADR 0053 — Conciliación de pagos cuyo aviso no llegó

Estado: Aceptado (2026-09-27). Relacionados: ADR 0013 (webhooks idempotentes),
0029 (reembolsos tolerantes a fallos), 0030 (pagos tardíos y cobros dobles).

## Contexto

Un cobro en línea queda `pendiente` hasta que la pasarela avisa (webhook). Si el
aviso no llega (webhook mal configurado, caído o sin reintentos), nada lo recuperaba:

- el pago seguía pendiente aunque el dinero ya estuviera cobrado;
- una cita con pago para reservar vencía a los 30 minutos y liberaba el lugar;
- al vencer, el cierre del intento veía «ya se cobró» en la pasarela, lo anotaba en
  el log y esperaba un aviso que no iba a llegar;
- los intentos cerrados de nuestro lado sin confirmación de la pasarela (OpenPay no
  cancela cargos; la pasarela pudo no responder) se daban por muertos, aunque un pago
  en tienda todavía podía completarse.

Solo los reembolsos y los cobros de suscripciones se conciliaban solos.

## Decisión

- **`PasarelaConsultable::consultar()`** pregunta cómo va un intento sin tocarlo:
  aprobado (con la misma referencia que traería su aviso), pendiente o rechazado.
  - **Stripe**: la sesión de Checkout con su cobro expandido. Pagada → aprobada;
    vencida → rechazada; completa sin pagar → pago en tienda en espera, salvo que el
    cobro ya haya vencido o fallado. Los cargos automáticos (`pi_`) por su estado.
  - **Mercado Pago**: los cobros de nuestro pago (`external_reference`). Aprobado →
    aprobado con su id; en espera → pendiente (se guarda su referencia, como hace el
    aviso). Sin cobro vivo, sigue pagable solo si la preferencia está vigente y no la
    cerramos.
  - **OpenPay**: el cargo por su id.
- **`agendauno:conciliar-pagos`** (cada 5 minutos, `ConciliarPagosTenant`) revisa:
  - los pagos `pendiente` con más de 5 minutos (el aviso normal llega en segundos);
  - los `rechazado` con `cerrado_sin_confirmar`. Todo cierre de este lado lo marca:
    al reintentar, al vencer un apartado o al confirmarse otro intento.

  Los revisa mientras un pago en tienda pueda completarse: la vigencia
  (`cobranza.dias_pagar_en_tienda`) más 2 días. Espacia las consultas con la edad del
  intento: cada vuelta la primera hora, cada 30 minutos el primer día y cada 2 horas
  después (`revisado_en`).
- **Aplica lo mismo que el aviso.** Aprobado → `ConfirmarPagoTenant::aprobar()`:
  entrega la compra, o la atiende como pago tardío o cobro doble. Es idempotente con
  el aviso: los dos bloquean el pago y solo cuenta el primero. Si el cobro de Stripe
  guardaba la tarjeta para pagos automáticos, también se registra. Rechazado → el
  intento queda cerrado y deja de preguntarse. Nunca cobra ni cancela.
- **Deja rastro**: bitácora `pago.conciliado` y una alerta al superadmin
  «aviso de pago perdido», agrupada por negocio y pasarela, porque un aviso perdido
  casi siempre es un webhook mal configurado.
- Si la pasarela no responde, se anota la consulta y se reintenta en la siguiente
  vuelta que toque.

## Consecuencias

- Una cita pagada cuyo aviso se perdió queda confirmada antes de que venza su
  apartado (conciliación a los 5–10 minutos; el apartado dura 30).
- Un pago en tienda de OpenPay cerrado de este lado y pagado después se reconoce y,
  si ya había otro pago, queda como cobro doble por devolver.
- Consultas extra a la pasarela: una por intento en Stripe y Mercado Pago cuando se
  cierra con su confirmación; los pagos en tienda se consultan hasta que se resuelven
  o vence su referencia.
- Las sesiones de Stripe en modo `setup` (autorizar tarjeta sin pagar) no son pagos;
  se concilian aparte desde el ADR 0076.
