# ADR 0217 — Quién da cada servicio (citas)

Estado: Aceptado (2026-10-26). Completa el ADR 0065 (agendar citas) y el 0104
(modalidad excluyente).

## Contexto

En un negocio de citas cualquier profesional con horario aparecía para cualquier
servicio: a la colorista se le podía agendar un corte y al barbero un tinte. La
revisión para la V1 lo marcó como bloqueo: el cliente agendaba con quien no da el
servicio y el negocio tenía que reacomodar a mano.

## Decisión

- Cada servicio de un negocio de citas puede tener a sus profesionales
  (`oferta_profesionales`). Sin ninguno, lo da cualquiera del equipo, como hasta hoy.
  Se eligen en el catálogo (solo en citas y con más de un profesional).
- La disponibilidad lo respeta: quien no da el servicio no tiene huecos para él; con
  «cualquier profesional» los huecos son solo de quienes lo dan, y los días del
  calendario también (`oferta_id` en `/citas/dias` y `/mi/citas/dias`).
- Agendar con alguien que no lo da se rechaza («Ese profesional no da este servicio»),
  también cuando agenda el negocio; con «cualquiera» le toca a quien lo da.
- Las opciones de cita dicen quién da cada servicio (`profesionales`, null = todos):
  la página pública, la cuenta del cliente, la Nueva cita del equipo y la app solo
  ofrecen a esas personas.
