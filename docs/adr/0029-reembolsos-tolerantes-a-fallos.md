# ADR 0029 — Reembolsos tolerantes a reintentos y fallos (fase 1, punto 1.1)

Estado: Aceptado (2026-09-25).

## Contexto

La devolución se pedía a la pasarela dentro de la transacción y ANTES de guardar su
registro: si algo fallaba después, el registro se deshacía pero el dinero ya se había
devuelto. La llave de idempotencia era aleatoria en cada intento (Stripe y Mercado
Pago) o no existía (OpenPay), así que un reintento podía devolver dos veces; un
rechazo se guardaba sin el motivo y no había dónde ver lo que quedó sin confirmar.

## Decisiones

- **Primero la intención**: la devolución se registra `solicitada` (con el pago
  bloqueado y contando para no devolver de más) y se confirma en la base; después se
  pide a la pasarela, fuera de la transacción.
- **Llave estable**: se pide con `reembolso_{ulid de la devolución}`. Reintentar con
  la misma llave hace que la pasarela responda lo de la primera vez. OpenPay no acepta
  llave: va en la descripción.
- **Estados**: solicitada → aprobada | pendiente (la confirma el webhook) | fallida
  (la pasarela dijo que no; se guarda su motivo) | **incierta** (no respondió, o
  respondió 5xx: pudo haber devuelto). Una devolución aprobada o fallida ya no cambia;
  los efectos (créditos, membresías, estado del pago, orden, evento) se aplican **una
  sola vez**, al aprobarse, con el pago y la devolución bloqueados. `aplicado_en`
  guarda cuándo se devolvió de verdad.
- **Aclarar lo incierto** (`agendauno:conciliar-reembolsos`, cada 5 min): se vuelve a
  pedir con la misma llave durante 23 h en pasarelas que la respetan (Stripe, Mercado
  Pago). Pasado eso, o en OpenPay, queda **por conciliar** para que alguien revise el
  panel de la pasarela y diga si se hizo.
- **Por conciliar** (`incidencias_cobro`): bandeja en Cobranza con lo del dinero que
  necesita revisión, quién lo resolvió, cuándo y qué nota dejó. La reutilizará el pago
  tardío (1.2).
- **Doble clic**: la pantalla manda una llave por intento (`Idempotency-Key`); repetir
  la solicitud devuelve la misma devolución.
- "Reembolsable" ya no descuenta devoluciones fallidas; sí las que están en curso.

## Consecuencias

- Un intento que la pasarela rechaza queda registrado como fallido (antes no quedaba
  nada); la respuesta sigue siendo 422 con el motivo.
- Una devolución hecha directamente en el panel de la pasarela sigue sin aparecer en
  la app (no hay registro previo que conciliar); se registra como devolución manual.
