# ADR 0046 — Devolver el pago cuando el negocio cancela

Estado: Aceptado (2026-09-26). Usa los ADR 0042 (parámetros configurables) y de
devoluciones con intención (reintento seguro con llave).

## Contexto

Cuando el negocio cancela una cita o clase que el cliente ya pagó, el dinero no se
devolvía solo. La vista previa lo advertía y el negocio tenía que ir a Pagos a
devolverlo. Muchos negocios prefieren que, si ellos cancelan, el cliente reciba su
dinero sin trámite. Otros prefieren ofrecer otro horario primero. Debe decidirlo cada
negocio.

## Decisiones

- **Parámetro del negocio** `cancelacion.devolver_pago_si_cancela_negocio` (sí/no).
  Apagado al inicio; el superadmin puede cambiar el valor de la plataforma.
- **Cuándo aplica**: solo si cancela el negocio (la reserva o la sesión completa) y
  solo en pagos en línea (Stripe, Mercado Pago, OpenPay). Si cancela el cliente,
  aplica su política de cancelación. Lo pagado en efectivo se devuelve en caja, a
  mano.
- **Asíncrono y a prueba de repeticiones**: un consumidor del outbox
  (`DevolverPagoAlCancelarNegocio`) atiende `reserva.cancelada` y
  `reserva.sesion_cancelada`. Pide la devolución total con la llave
  `cancelacion-negocio:{reserva}`. Si el evento llega dos veces, se devuelve una.
  - Si la pasarela no responde, la devolución queda "por conciliar", como cualquier
    otra.
  - Si ya se devolvió o la pasarela no devuelve en línea, no hace nada más: el
    negocio lo ve en Pagos.
- **La vista previa lo dice**: al cancelar, el negocio ve qué pagos se devolverán solos
  y cuáles no (`se_devuelven`).

## Consecuencias

- La devolución total cancela la orden, como una devolución manual.
- Devolver solo una parte, o un crédito en lugar de dinero, queda fuera por ahora.
