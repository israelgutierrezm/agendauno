# ADR 0035 — Cierre de la fase 1: recorridos completos y avisos que no se repiten

Estado: Aceptado (2026-09-26). Cierra la fase 1 ("cerrar bloqueos de operación",
ADR 0028 a 0034).

## Contexto

La fase 1 termina cuando pasan pruebas integrales de los dos recorridos de la
operación. No basta con que cada pantalla funcione por separado. También deben
probarse solicitudes simultáneas, permisos, aislamiento, notificaciones repetidas y
recuperación ante fallos.

Al revisar los consumidores del outbox apareció un hueco. El outbox entrega "al
menos una vez": si un consumidor falla, el evento completo se reintenta.

- Los puntos de lealtad y las automatizaciones ya se deduplicaban por evento.
- **Los avisos** (correo o push) **y los webhooks salientes no**: un reintento
  volvía a mandar el mismo correo y a entregar el mismo webhook.

## Decisiones

- **Avisos sin repetir**:
  - Cada mensaje generado por un evento lleva una llave de envío:
    `evento:plantilla:destinatario`, con índice único en `mensajes.clave_envio`.
  - Si ya existe, no se vuelve a generar.
  - La entrega de un webhook se busca por (endpoint, evento) antes de crearse; sus
    reintentos van por su propio relay.
  - Los mensajes anteriores quedan sin llave (el índice único admite varios NULL).
- **Procesos diarios que no se enciman**: `entitlements:generar-ciclos` y
  `agendauno:escalar-dunning` corren con `withoutOverlapping`, como el resto.
- **Recorridos completos** en `RecorridosOperacionTest`, por la API:
  - **Clases**: la alumna se registra sola. Compra la mensualidad y la paga en
    recepción. Reserva tres clases y asiste a una. Cancela una a tiempo (el crédito
    regresa) y otra tarde (se cobra), viendo antes el efecto. El historial explica
    el saldo y los avisos no se repiten al publicar otra vez. Tres días antes se abre
    la renovación, se paga, y al cambiar de ciclo llegan los créditos nuevos.
  - **Citas**:
    - disponibilidad → cita por pagar (el horario se aparta) → pago en línea
      confirmado por webhook, aunque Stripe avise dos veces;
    - atención: ya no se puede cancelar;
    - cita pagada que cancela el negocio: la vista previa advierte que el dinero no
      regresa solo, el horario se libera y recepción devuelve el pago;
    - la caja cuadra;
    - permisos: el profesional no reembolsa y otra alumna no cancela;
    - aislamiento: otro negocio no encuentra la reserva.
  - **Recuperación**: un consumidor falla una vez en `reserva.confirmada`; el
    reintento no duplica el aviso ni el webhook. La prueba falla sin la corrección.
- La concurrencia de agenda y reservas ya está probada:
  - agenda: 10 procesos simultáneos, ADR 0033;
  - reservas: candados de cupo y créditos, más las pruebas de doble cancelación y
    asistencia de ADR 0034.

## Consecuencias

- Un consumidor nuevo del outbox debe ser idempotente por `eventoUlid`, como estos.
- Los avisos al profesional son push. En pruebas sin FCM no se generan; su
  deduplicación es la misma que la de los avisos a la persona.
- Pendiente para la fase 2 (agenda cotidiana): reprogramar, bloqueos por
  profesional, días cerrados por sede y editar series.
