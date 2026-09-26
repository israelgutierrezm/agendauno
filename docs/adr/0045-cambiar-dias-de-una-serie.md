# ADR 0045 — Cambiar los días de una clase recurrente

Estado: Aceptado (2026-09-26). Extiende el ADR 0040 ("esta y las siguientes") y usa
el ADR 0042 (parámetros configurables).

## Contexto

"Esta y las siguientes" cambiaba la hora, la duración, el profesional o la sala desde
una fecha, pero no los días. Pasar una clase de lunes y miércoles a martes y jueves
obligaba a cancelar la serie y crear otra: se perdían las reservas y el historial
quedaba partido sin relación.

## Decisiones

- **Días como un cambio más** (`dias_semana` en `POST /plantillas-horario/{id}/cambiar`),
  con la misma vista previa que se revierte y las mismas reglas del ADR 0040. Si la
  serie ya tuvo fechas antes, se parte en dos. Lo cambiado a mano o con asistencia se
  conserva.
- **Fechas de días que ya no van**:
  - con reservas activas se conservan y se explica por qué ("Tiene reservas: cancela
    esa fecha o cambia a las personas de fecha"). Cancelarlas o mover a las personas es
    decisión del negocio;
  - si nunca se usaron (sin reservas, pagos, check-ins, accesos ni personal) se
    borran: no hay historial que cuidar;
  - si tuvieron movimiento se cancelan, para no perder ese historial.
- **Días nuevos**: se crean sus fechas hasta donde ya estaba generada la serie, o hasta
  el horizonte del negocio si llega más lejos. Aplican las reglas de generación: lo que
  choca no se crea y se explica (`omitidas`).
- **Horizonte configurable**: cuántos días adelante se generan las clases recurrentes
  (`agenda.dias_a_generar`, 30) lo fija cada negocio. El superadmin fija el valor de la
  plataforma. La tarea diaria lo usa; `--dias` queda solo para forzarlo a mano.
- **Nada revive**: todas las fechas desde el cambio pasan a la serie nueva, también las
  canceladas. Antes, una fecha cancelada se quedaba en la serie anterior y la
  generación diaria de la nueva podía volver a crearla.

## Consecuencias

- La agenda muestra los días actuales de la serie (`serie_dias`) y "Esta y las
  siguientes" permite elegirlos. La vista previa dice cuántas fechas se quitan, cuántas
  se crean y cuáles no se pudieron crear.
