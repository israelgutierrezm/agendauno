# ADR 0134 — Pago automático seguro: cancelación confirmada, precio contratado y conciliación que compara

Estado: Aceptado (2026-10-11). Pedido del dueño, «bloqueos antes de publicar», puntos
A y B. Decisión del dueño sobre B: «su precio contratado».

## Contexto

Con Mercado Pago y OpenPay el pago automático es una suscripción que la pasarela cobra
por su cuenta (ADR 0076). Hasta ahora:

- **A. Una cancelación local podía no cancelar el cobro externo.**
  - Quitar el pago automático marcaba la domiciliación «cancelada» antes de pedirlo a
    la pasarela. Si la pasarela fallaba o tardaba, solo se reportaba el error: la
    suscripción seguía cobrando y nadie se enteraba.
  - Al autorizar una suscripción nueva (otra tarjeta, otro pagador u otra pasarela),
    las anteriores se cancelaban solo de este lado.
  - Si el negocio apagaba familias, la suscripción del responsable seguía cobrando
    hasta la siguiente corrida.
  - Un cobro que llegaba después de cancelar no se asentaba en ningún lado.
- **B. Una renovación podía quedar pagada con un importe insuficiente.**
  - La conciliación liquidaba la renovación con cualquier cobro aprobado, sin
    comparar importe ni moneda.
  - La renovación se abría con el precio de hoy, mientras la suscripción cobraba el
    precio con que se autorizó.

## Decisión

**A. La cancelación de una suscripción se confirma con la pasarela:**
- Estado nuevo de la domiciliación: `cancelando` («cancelación pendiente»).
- Quitar el pago automático, pausar la membresía, retirar la autorización de un
  responsable o reemplazar la suscripción la dejan en `cancelando`:
  - no se cobra desde aquí ni cuenta como pago automático;
  - se pide la cancelación a la pasarela.
- Solo queda `cancelada` cuando la pasarela la da por cancelada:
  - al consultarla después de pedirla;
  - por su aviso (webhook);
  - o porque ya no existe (OpenPay responde 404).
- **Reintentos persistentes:** `agendauno:confirmar-cancelaciones`, cada 5 minutos,
  en cada negocio.
  - Primero consulta: si ya está cancelada, la confirma sin volver a pedirla.
  - Espera entre intentos: 5 min, 15 min, 1 h, 3 h, 6 h y luego 12 h. No se rinde.
  - Cada intento reclama la domiciliación (`cancelacion_proximo_en`): dos corridas no
    la piden a la vez.
- Si no se confirma tras 5 intentos (unas 4 horas), se abre la incidencia
  `cancelacion_sin_confirmar` en «Por conciliar», con la referencia, para cancelarla
  a mano en el panel de la pasarela. Se cierra sola al confirmarse.
- **Reemplazos:**
  - al autorizarse una suscripción nueva, las anteriores de esa membresía se cancelan
    en la pasarela;
  - con tarjeta domiciliada (Stripe), las de otra pasarela también;
  - si el negocio apaga familias, se cancelan las que paga un responsable por otra
    persona.
- **Cobro después de cancelar:** se concilia también lo que la pasarela cobre a una
  domiciliación `cancelando` o `cancelada`. El dinero nunca se pierde.
  - Si paga exacto la renovación que se debe: se aplica, y se avisa en «Por
    conciliar» (`cobro_tras_cancelar`) por si el cliente no la quería.
  - Si no hay deuda: se registra el pago sin aplicarlo y queda la incidencia para
    devolverlo.

**B. La conciliación compara antes de liquidar:**
- Cada cobro de suscripción trae su importe y su moneda.
  - Si coinciden con la renovación, se liquida como antes.
  - Si cobró **menos** u **otra moneda**:
    - se registra el pago sin liquidar;
    - queda la incidencia `monto_distinto`;
    - el negocio decide: devolverlo, o «dar por pagada» la renovación aceptando la
      diferencia.
    - Mientras esté abierta, no se avisa al cliente que no llegó su cobro.
  - Si cobró **de más**:
    - se liquida;
    - la incidencia permite devolver solo el excedente.

**B. Precio contratado:**
- La domiciliación guarda lo que el cliente autorizó (`monto_minor`, `moneda`): el
  precio del plan al suscribirse o al domiciliar la tarjeta.
- Con pago automático vigente, la renovación se abre con ese precio, no con el de
  hoy. Aplica a todas las pasarelas, también a Stripe, donde cobra el sistema.
- Si el precio del plan cambió:
  - el aviso de renovación dice el precio que se cobrará, el nuevo y que puede
    autorizarlo en su cuenta;
  - «Pago automático» lo muestra, con «Autorizar el nuevo precio»:
    - Stripe: actualiza el monto autorizado;
    - suscripción: se crea otra al precio nuevo y la anterior se cancela, con
      confirmación, al autorizarse.
- Las domiciliaciones anteriores a este cambio no tienen monto guardado. Siguen con el
  precio de hoy, y la comparación de B detecta cualquier diferencia.

## Consecuencias

- Lo que la pasarela cobra queda siempre asentado: liquidado, por devolver o por
  revisar.
- El negocio ve en «Por conciliar»:
  - las cancelaciones que la pasarela no confirma;
  - los cobros que llegaron tras cancelar;
  - los cobros con otro importe.
  El superadmin recibe la alerta de cada incidencia nueva.
- Subir el precio de un plan no cambia el cobro automático de nadie sin su
  autorización.
- Migración de negocio 000128.
