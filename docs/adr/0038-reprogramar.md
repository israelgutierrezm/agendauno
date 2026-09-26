# ADR 0038 — Reprogramar sin cancelar (fase 2, puntos 2.1 y 2.7)

Estado: Aceptado (2026-09-26). Usa las reglas únicas de agenda (ADR 0033, 0036,
0037) y los avisos sin repetir (ADR 0035).

## Contexto

Para cambiar una cita de hora, recepción tenía que cancelarla y volver a capturarla.
Eso perdía la relación con su pago (y a veces lo cobraba dos veces), no avisaba bien
al cliente y dejaba salir el recordatorio del horario anterior.

## Decisiones

- **Tres movimientos distintos**:
  - **Cita** (`POST /reservas/{r}/reprogramar` con `inicia_en_local` y, opcional,
    `instructor_id`):
    - se mueve su sesión con la misma duración;
    - es la misma reserva, con su orden y sus pagos, así que no se vuelve a cobrar;
    - otro profesional debe atender citas.
  - **Alumno de una clase** (mismo endpoint con `sesion_id`):
    - pasa a otra fecha de la **misma** clase y conserva su crédito apartado y su
      canal;
    - se revalida el cupo del destino para su canal y que no tenga ya lugar ahí;
    - el cupo que deja se ofrece a la lista de espera.
  - **Clase completa** (`POST /sesiones/{s}/reprogramar`): cambia su horario y todas
    sus reservas la siguen.
- **Revalidado al guardar**, en una transacción y bajo candado, con el verificador
  único: choques, márgenes de servicio, bloqueos y sede. Si el nuevo horario ya no
  está libre, lo original queda intacto (422 con el motivo). Lo que tiene asistencia
  ya ocurrió y no se mueve (409).
- **Horario anterior y nuevo**:
  - la respuesta trae `antes`/`ahora`;
  - la bitácora guarda ambos (`reserva.reprogramada` / `sesion.reprogramada`);
  - el aviso `reserva.reprogramada` lleva `{{antes_fecha}}`/`{{antes_hora}}` y el
    horario nuevo. Por defecto va por correo y push al cliente, y por push al
    profesional de la cita.
- **Recordatorios (2.7)**:
  - Los del horario anterior que aún no salen se descartan: el evento sin publicar se
    cierra con su motivo y el mensaje sin enviar pasa al estado nuevo `descartado`,
    que el relay no reintenta.
  - Los del horario nuevo salen a su hora. Si su momento ya pasó, se dan por
    enviados, porque ya recibió el aviso del cambio.
- **Pantallas**:
  - "Reprogramar" en el detalle de la cita (día, hora y profesional);
  - "Mover a otra fecha" en la lista de la clase (próximas fechas con lugar);
  - "Cambiar horario" en el detalle de la clase.
  - Formularios claros. Arrastrar y soltar queda como mejora posterior.

## Consecuencias

- Reprogramar desde la cuenta del cliente queda pendiente; hoy lo hace el negocio.
- Mover a un alumno a otra clase (no otra fecha de la misma) requiere cancelar y
  reservar, porque el costo en créditos puede ser distinto.
