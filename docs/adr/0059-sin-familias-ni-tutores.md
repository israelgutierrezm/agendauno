# ADR 0059 — Sin familias ni tutores en la V1

Estado: Aceptado. Registra la decisión del 2026-09-20 (migración tenant
`2026_09_16_000040_drop_familias`).

## Contexto

El diseño inicial incluía hogares, tutores y dependientes para las escuelas de
natación: una madre con cuenta que reserva y paga por sus hijos. Se construyó una
primera versión (`hogares`, `tutelas`, `personas.hogar_id`).

Al revisar las prioridades de la V1 se vio que:

- ningún flujo prioritario la necesitaba (inicio por rol, recepción, embudo público,
  portal del alumno, padrón facturable, onboarding);
- complicaba cada pantalla del portal y de la app (¿para quién reservo?, ¿de quién es
  este saldo?) y cada regla de acceso;
- recepción ya puede dar de alta y cobrar a un alumno sin cuenta, y una línea de orden
  puede tener un beneficiario distinto del comprador.

## Decisión

- Se quitan hogares, tutelas y dependientes: tablas, columna, código, pantallas y
  documentación.
- Comprador ≠ beneficiario se conserva en `lineas_orden`.
- Un menor se atiende como alumno propio (con o sin cuenta); el equipo reserva y
  cobra por él.

## Consecuencias

- El portal, la app y la autorización trabajan con una sola persona por cuenta.
- Si un piloto lo exige, se diseña de nuevo sobre el modelo actual (una base por
  negocio, rol activo) con su propio ADR.
