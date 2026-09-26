# ADR 0036 — Tiempos de preparación y limpieza (fase 2, punto 2.3)

Estado: Aceptado (2026-09-26). Extiende el ADR 0033: las reglas de agenda miden lo
que ocupa cada sesión.

## Contexto

Un servicio puede necesitar tiempo antes (preparar la cabina) o después (limpiar,
tomar notas). Ejemplo: una terapia de 60 minutos con 15 de limpieza ocupa 75 minutos
de agenda, pero al cliente se le comunica una sesión de 60.

Antes, la agenda solo conocía la atención. La siguiente cita podía quedar pegada a
la anterior sin el tiempo de limpieza. Además, la disponibilidad tomaba la duración
que mandaba la pantalla.

## Decisiones

- **El servicio define sus márgenes**: `ofertas.preparacion_min` y
  `ofertas.limpieza_min` (0 a 240; por defecto 0, así nada cambia si no se usan).
- **La sesión congela los suyos** al crearse (`margen_antes_min`,
  `margen_despues_min`) y guarda lo que ocupa (`ocupa_desde`, `ocupa_hasta`).
  - El modelo lo recalcula cada que se guarda, así que un cambio de horario lo
    arrastra.
  - Cambiar los márgenes del servicio no mueve lo ya agendado.
- **La atención y lo ocupado van separados**:
  - `inicia_en`/`termina_en` siguen siendo lo que se le dice al cliente (avisos,
    Mi cuenta, página pública).
  - El equipo ve además `ocupa_desde`/`ocupa_hasta`: la agenda por profesional
    dibuja ese tramo tenue junto a la cita.
- **Los choques se miden sobre lo ocupado** en el verificador único (ADR 0033), para
  el profesional y para la sala. Si solo chocan los márgenes, el mensaje lo dice:
  "Ese horario invade la preparación o limpieza de Masaje de las 10:00."
- **Disponibilidad**:
  - Acepta `oferta_id`; con él toma la duración y los márgenes del servicio. Sin él
    usa la duración que llega, sin márgenes propios.
  - Los márgenes de lo ya agendado se respetan siempre.
  - El paso por defecto incluye los márgenes (una cita tras otra con su tiempo entre
    ellas).
  - Mi cuenta y la app mandan el servicio.
- **Horario de atención**: la atención debe caber en el horario del profesional. Los
  márgenes pueden quedar en el borde (preparar antes de abrir o limpiar al cerrar);
  solo se exige que no invadan otra sesión.

## Consecuencias

- La página pública de citas todavía no manda el servicio al pedir disponibilidad.
  El servidor valida los márgenes al agendar y los de las citas existentes se
  respetan, pero un hueco pegado a otra cita podría ofrecerse y rechazarse al
  confirmarlo.
- Recursos requeridos por servicio (cabina, sillón) quedan para el 2.4 y usarán el
  mismo tramo ocupado.
