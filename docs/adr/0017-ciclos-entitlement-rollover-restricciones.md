# ADR 0017 — Ciclos de entitlement, rollover, add-ons y restricciones

Estado: Aceptado (Slice 5c)

## Contexto

El motor de membresías (MEMBERSHIP_ENGINE.md) pide, además de packs limitados
(5a/5b): reinicio por ciclo (calendario/aniversario), rollover, add-ons/top-up y
restricciones por actividad/sucursal. El saldo sigue derivándose del ledger
(ADR-0009): los ciclos se expresan como asientos, nunca como un saldo guardado.

## Decisiones

- **Config en producto → derecho**. El producto define la plantilla
  (`politica_reset`, `unidades_por_ciclo`, `politica_rollover`, `rollover_max`,
  `actividad_id`, `sucursal_id`); `CrearAcuerdo` la copia al derecho e inicializa
  el primer ciclo. Pack simple = `politica_reset = ninguno` (concede
  `creditos_incluidos`, sin renovación).
- **Reset como asientos**. `GenerarCicloEntitlement` avanza los ciclos vencidos:
  al cerrar cada ciclo aplica el rollover (`ninguno`/`completo`/`limitado` con
  `rollover_max`), **expira** en el ledger lo no acarreado (`TipoMovimiento::Expiracion`,
  signo negativo), abre el ciclo siguiente (calendario = mes natural; aniversario
  = mes desde el ancla) y concede su cupo. Idempotente (solo avanza si el ciclo
  actual venció); corre a diario con `agendauno:generar-ciclos` (scheduler).
- **Add-ons/top-up**. `AgregarTopUp` suma un asiento `add_on` separado, sin editar
  la membresía (MEMBERSHIP_ENGINE: "create a separate grant and ledger entries").
  Endpoint `POST /derechos/{}/topups`.
- **Restricciones**. `ResolverDerecho` (booking) filtra los derechos que cubren la
  sesión: `actividad_id`/`sucursal_id` nulos cubren cualquiera; si están fijos,
  deben coincidir con la actividad de la oferta y la sucursal de la sesión.

## Consecuencias

- El reset asume que no hay holds activos en el instante de cierre (bookings dentro
  del ciclo); expirar créditos retenidos sería incorrecto. El rollover se calcula
  sobre el saldo del ledger. Casos de cierre con holds vivos quedan como afinación.
- El `MAX_CICLOS` acota el catch-up de derechos muy atrasados en una corrida.
- La UI para configurar ciclo/rollover/restricciones en el producto y para top-ups
  queda como trabajo de frontend (hoy la API los acepta).
