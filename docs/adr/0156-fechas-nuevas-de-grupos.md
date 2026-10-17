# ADR 0156 — Las fechas que se generan después también son de los inscritos

Estado: Aceptado (2026-10-17). Completa R25 (grupos y cursos) y las bajas y cierres de
grupo (2026-10-17).

## Contexto

Al inscribir a alguien en un grupo se le reservan las fechas que **ya existen** de su
serie. La agenda se genera por ventana (`agendauno:generar-agenda` cada noche, con los
días que fija el negocio) y al cambiar los días de una serie. Esas fechas nuevas
quedaban sin los inscritos: a la semana el curso aparecía vacío y recepción tenía que
volver a inscribir a cada persona.

## Decisión

- Al generar sesiones (`GenerarAgendaTenant`), si un grupo **abierto** sigue esa
  serie, en la misma transacción queda en el outbox `agenda.sesiones_generadas` con
  los ULID de las fechas nuevas.
- `ReservarInscritosEnFechasNuevas` (consumidor del outbox) reserva a cada inscrito
  vigente de cada grupo abierto de la serie, con el mismo motor y los mismos motivos
  que al inscribir (`InscribirEnGrupoTenant::reservarFecha`): plan, cupo y lista de
  espera.
  - Es idempotente: quien ya tuvo una reserva en esa fecha, aunque la haya cancelado,
    no se vuelve a reservar.
  - Quien se dio de baja (inscripción inactiva) y los grupos cerrados no reservan.
  - El resumen queda en la bitácora del grupo (`grupo.fechas_nuevas`): cuántas se
    reservaron, cuántas quedaron en espera y cuáles no se pudieron reservar y por qué.
- Las reservas se hacen fuera de la petición o del comando que generó la agenda: el
  relay del outbox corre cada minuto.
- **Eventos internos**: un webhook saliente suscrito «a todo» recibe solo el catálogo
  público (`EventoDeDominioTenant::TIPOS`). `agenda.sesiones_generadas` no está en él,
  así que nunca sale a terceros ni aparece para las plantillas de mensajes.

## Consecuencias

- Las fechas nuevas tienen a sus inscritos en menos de un minuto.
- Las fechas que se generaron antes de este cambio no se reservan solas. Para ellas
  basta con volver a inscribir: se respeta lo que ya existía.
- Quien no tiene saldo para una fecha nueva no la pierde en silencio: la bitácora dice
  por qué y recepción puede venderle un plan y volver a inscribirlo.
