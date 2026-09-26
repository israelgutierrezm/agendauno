# ADR 0027 — Avisos al equipo del negocio

Estado: Aceptado (2026-09-25). Continúa el ADR 0026 (avisos al profesional).

## Contexto

Hay cosas que el negocio debe atender y de las que nadie se enteraba sin entrar al
panel: una solicitud de baja de datos (la ley da 20 días hábiles para responder), una
reseña baja, un cobro fallido o una membresía suspendida.

## Decisiones

- Tercer destinatario de los mensajes automáticos: **`equipo`**. Le llega a quien
  tiene el **permiso con el que se atiende** lo que pasó, no a un rol por nombre
  (`AvisosAlEquipo`):
  - solicitud de baja de datos y reseña nueva → `miembros.gestionar`;
  - cuenta nueva → `miembros.ver`;
  - venta pagada, cobro fallido y membresía suspendida → `facturacion.ver`.
- Cada destinatario solo aplica a sus eventos: al profesional, los de reservas (traen
  la cita); al equipo, los de la lista anterior. La API los expone
  (`destinatarios`) y rechaza combinaciones que no aplican.
- Por correo o push, igual que al profesional; cada aviso guarda a quién se envió
  (`usuario_id`) y de quién trata (la persona).
- **Activos de fábrica** los que piden acción: solicitud de baja de datos (correo +
  push, con el plazo y el enlace a Privacidad) y reseña nueva (push, con la
  calificación). **Apagados**, listos para activarse: venta, cobro fallido, membresía
  suspendida y cliente nuevo (podrían ser demasiados en un negocio grande).

## Consecuencias

- Si cambian los permisos de un rol, cambia quién recibe los avisos, sin tocar las
  plantillas.
