# ADR 0037 — Bloqueos de agenda (fase 2, punto 2.2)

Estado: Aceptado (2026-09-26). Extiende los ADR 0033 (verificador único) y 0036
(tramo ocupado).

## Contexto

La agenda solo conocía dos cosas: el horario de atención del profesional y los días
cerrados de todo el negocio (`excepciones_horario`). No había forma de decir "Ana
come de 14:00 a 15:00", "Beto está de vacaciones del 8 al 10", "la sede de Polanco
cierra el jueves por fumigación" o "la cabina 1 está en mantenimiento". Recepción lo
resolvía agendando citas falsas o de memoria.

## Decisiones

- **Un bloqueo es de una sola cosa**: un profesional, una sede o una sala
  (`bloqueos_agenda` con exactamente uno de `instructor_id`, `sucursal_id` o
  `recurso_id`).
  - Por horas (`desde_local`/`hasta_local`) o por días completos
    (`fecha_desde`/`fecha_hasta`, de 00:00 del primero a 00:00 del siguiente al
    último).
  - La zona es la de la sede (para un profesional, la del negocio). Se guarda en UTC
    junto con su zona.
  - Lleva motivo obligatorio y quién lo puso. Queda en la bitácora
    (`bloqueo_agenda.creado`) y se quita con baja lógica.
- **Se respeta en todas partes** porque vive en el verificador único:
  - autoservicio (disponibilidad y citas de la cuenta o la página pública);
  - recepción (crear sesión, agendar cita, reasignar);
  - clases recurrentes (la fecha se omite con su motivo).
  - Mensajes: "Esa persona no está disponible en ese horario (Comida).", "La sede
    está cerrada en ese horario (Fumigación).", "Cabina 1 está bloqueado en ese
    horario (Mantenimiento)."
- **No cancela en silencio**. Lo ya agendado sigue en pie:
  - `POST /bloqueos/previsualizar` dice qué sesiones programadas caen ahí y cuántas
    reservas tienen;
  - la pantalla lo advierte antes de guardar ("Bloquear de todos modos");
  - al crearlo se devuelven otra vez para atenderlas.
- **Visible**: la agenda por profesional sombrea los bloqueos (suyos y de la sede)
  con su motivo, como el "fuera de horario". Horarios tiene la sección Bloqueos de la
  persona y la sede elegidas.
- Los días cerrados de todo el negocio (`excepciones_horario`) siguen como estaban.

## Consecuencias

- Los bloqueos de sala se crean por la API; su pantalla va con el 2.4 (recursos en
  citas).
- Que el profesional bloquee su propio tiempo requiere un permiso nuevo; por ahora lo
  hace quien gestiona la agenda.
