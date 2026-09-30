# ADR 0076 — Conciliar la tarjeta del pago automático sin aviso

Estado: Aceptado (2026-09-30).

## Contexto

Con Stripe, el alumno autoriza su tarjeta del pago automático en una sesión de
Checkout en modo `setup` (ADR 0020), desde su cuenta al activarlo o al cambiar de
tarjeta. La tarjeta se registraba solo con el aviso `checkout.session.completed`.

La conciliación del ADR 0053 cubre los cobros, incluida la compra que deja la
tarjeta guardada. Pero una sesión `setup` no es un pago y no dejaba rastro local:
si su aviso se perdía, la membresía seguía sin pago automático aunque el alumno
creía haberlo activado.

## Decisión

- **Registro de la sesión** (`sesiones_tarjeta`, en la base del negocio):
  - Al abrir la página de Stripe para autorizar una tarjeta se guarda la sesión:
    la persona, la pasarela, la referencia `cs_…` y el estado `pendiente`.
  - No guarda nada de la tarjeta. La metadata (qué membresías activar) sigue en
    Stripe.
  - `iniciarGuardado` de `PasarelaDomiciliable` devuelve la `referencia` de la
    sesión.
- **Con el aviso:** `TarjetasStripe` marca la sesión `completada` al leerla de Stripe
  y registra la tarjeta como antes.
- **Sin el aviso:** `agendauno:conciliar-pagos` (cada 5 minutos) también corre
  `ConciliarTarjetasStripe` en cada negocio. Para cada sesión pendiente:
  - Espera 10 minutos a que llegue el aviso y luego pregunta como máximo cada
    15 minutos.
  - **Completada:** registra la tarjeta y activa las membresías, igual que el
    aviso. `registrarTarjeta` ya era idempotente, así que un aviso que llega tarde
    no duplica nada.
    - Queda en la bitácora (`tarjeta.conciliada`).
    - El superadministrador recibe la alerta «aviso de pago perdido», agrupada con
      la de los cobros por negocio y pasarela.
  - **Vencida en Stripe:** queda `expirada` y no se vuelve a preguntar.
  - **Abierta:** se vuelve a preguntar después.
  - A las 48 horas se da por vencida sin preguntar (Stripe las vence a las 24).
  - Si Stripe no responde, se reintenta en la siguiente vuelta.
- El comando suma «Tarjetas registradas: N» a su resumen.

## Consecuencias

- Activar el pago automático ya no depende de que llegue un aviso: a más tardar en
  unos 15 minutos queda activo.
- Una consulta extra a Stripe por sesión abierta y no terminada, cada 15 minutos,
  hasta que vence.
- Mercado Pago y OpenPay no cambian: sus suscripciones ya se concilian aparte
  (`ConciliarSuscripcionTenant`).
