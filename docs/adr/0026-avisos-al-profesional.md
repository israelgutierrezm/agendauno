# ADR 0026 — Avisos al profesional de sus citas

Estado: Aceptado (2026-09-25). Continúa el ADR 0025 (notificaciones push).

## Contexto

Los mensajes automáticos solo iban a la persona del evento (alumno o cliente). En
los negocios de citas (barbería, estética, consultorio) el profesional no se
enteraba de que le agendaron o cancelaron una cita hasta revisar la agenda.

## Decisiones

- Cada plantilla de mensaje dice **a quién va** (`destinatario`): la persona del
  evento (como hasta ahora) o el **profesional de la cita**. Única por (evento, canal,
  destinatario).
- El profesional se resuelve de la sesión del evento (`sesion_id`) y **solo en
  citas**: en una clase grupal no se avisa al instructor por cada reserva (serían
  demasiadas notificaciones). Un profesional dado de baja ya no recibe avisos.
- Al profesional se le avisa por **correo o push** (el equipo no tiene bandeja en la
  app). El mensaje guarda el usuario que lo recibe (`mensajes.usuario_id`) y también
  la persona de quien trata, así la baja de datos (ARCO) del cliente borra también los
  avisos que lo mencionan.
- Por defecto (push, activos y editables): "Nueva cita: {{persona_nombre}}" al
  confirmarse una cita y "Cita cancelada: {{persona_nombre}}" al cancelarse.

## Consecuencias

- La bandeja de salida muestra como destinatario al usuario del equipo cuando el
  aviso va al profesional.
- Avisos a otros perfiles del equipo (p. ej. a recepción o al dueño por una solicitud
  de baja de datos o una reseña baja) pueden sumarse como otro destinatario.
