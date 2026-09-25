# ADR 0024 — Avisos de cancelación y de renovación próxima

Estado: Aceptado (2026-09-24). Continúa los recordatorios (24 h / 2 h) y el pago
automático (ADR 0020, 0021).

## Contexto

El alumno no se enteraba cuando se cancelaba su lugar: ni si él (o recepción) cancelaba
su reserva, ni si el negocio cancelaba la clase o cita completa. Y quien paga su
membresía a mano solo recibía un aviso el día de la renovación, redactado como cobro
fallido ("no pudimos completar el cobro"); en un negocio sin pasarela en línea no
recibía nada y recepción no veía nada por cobrar.

## Decisiones

- **`reserva.cancelada`**: al cancelarse una reserva confirmada (o pendiente de pago),
  con los datos de la clase y qué pasó con el crédito ("regresó a tu cuenta" o "no se
  devuelve" por cancelación tardía con penalización). Dejar la lista de espera o
  rechazar un lugar ofrecido no avisa.
- **`reserva.sesion_cancelada`**: al cancelar el negocio la clase o cita, un evento por
  persona afectada (confirmadas, ofrecidas, en espera y pendientes de pago), con el
  enlace para reservar otro horario. No se envía además `reserva.cancelada`.
- **`membresia.renovacion_proxima`**, 3 días antes (`turnouno:avisar-renovaciones`,
  diario): cuándo, cuánto y cómo se paga. Una vez por periodo
  (`acuerdos.aviso_renovacion_para`, reclamado con UPDATE condicional junto al
  evento).
  - **Si paga a mano, la orden de renovación se abre desde el aviso** (la misma que
    reutiliza el cobro de ese día, `DeudaDeRenovacionTenant`): puede pagarla por
    adelantado en su cuenta o en recepción y, al pagarse, la fecha avanza al siguiente
    periodo, así que ese día ya no se cobra.
  - **Con pago automático no se abre antes**: una suscripción de la pasarela cobra por
    su cuenta y pagarla a mano la cobraría dos veces. Solo se avisa a qué tarjeta.
- **Al cancelarse una membresía** (baja del alumno, baja de datos o reembolso total),
  su orden de renovación pendiente se cancela: ya no queda nada por cobrar.
- Los tres correos llegan activos y editables en Comunicación → Automáticos.

## Consecuencias

- Una orden de renovación pendiente puede existir antes de la fecha de renovación; los
  reportes ya la distinguen como "Renovación".
- En un negocio sin pasarela en línea la renovación queda por cobrar en recepción,
  pero sin mora automática (no hay con qué reintentar el cobro).
