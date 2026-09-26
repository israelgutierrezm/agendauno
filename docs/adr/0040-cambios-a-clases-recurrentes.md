# ADR 0040 — Cambios sobre clases recurrentes (fase 2, punto 2.5)

Estado: Aceptado (2026-09-26). Complementa el R5 (generación de series), el ADR 0033
(verificador único) y el ADR 0038 (reprogramar).

## Contexto

- Una clase recurrente solo se podía crear, generar o dejar de repetir. Cambiarle la
  hora "de aquí en adelante" obligaba a borrar y rehacer. Eso perdía el historial y
  las reservas, o duplicaba sesiones.
- Además, la generación reconocía una fecha ya generada por su **hora exacta de
  inicio**. Al mover una sola sesión de una serie (2.1), el proceso diario la volvía
  a crear en su horario original: quedaba duplicada.

## Decisiones

- **Identidad de cada fecha de la serie**: `sesiones.fecha_serie` (día local al que
  pertenece). La generación salta una fecha que ya tiene su instancia, aunque esta
  se haya movido de hora o de día, editado o cancelado. Lo existente se llenó con su
  día local de inicio.
- **"Solo esta sesión"** es reprogramar (2.1) o reasignar el instructor de esa
  sesión. Queda marcada (`sesiones.editada_en`) y un cambio posterior a la serie la
  respeta.
- **"Esta y las siguientes"** (`POST /plantillas-horario/{p}/cambiar` con `desde`,
  y hora, duración, profesional o sala):
  - **Historial intacto**: si la serie ya tuvo fechas antes, se parte en dos. La
    anterior termina un día antes y una nueva, con el cambio, empieza en esa fecha.
  - **Sesiones futuras desde esa fecha**: se mueven con las reglas únicas de agenda y
    sus reservas reciben el aviso del cambio (y se rehacen sus recordatorios).
  - **Lo que no se mueve**: la que choca se queda como estaba con su motivo, y la
    cambiada a mano o con asistencia se conserva. Todo se explica en `conservadas`.
  - **Sin duplicados**: todas pasan a la serie nueva con su fecha de serie, así que
    volver a generar no crea nada.
  - **Vista previa** (`previsualizar`): corre exactamente lo mismo en una transacción
    que se revierte, así que lo que muestra es lo que se aplicaría.
  - Solo de hoy en adelante; el pasado no se toca.
- **Pantalla**: en el detalle de una clase recurrente están "Solo esta sesión" y
  "Esta y las siguientes" (hora, duración y profesional, con Revisar → Aplicar).

## Consecuencias

- Cambiar los días de la semana de una serie sigue siendo "dejar de repetir y crear
  otra"; un cambio de días con esta mecánica queda pendiente.
- Un cambio de solo el profesional no avisa a los alumnos (no cambia su horario).
