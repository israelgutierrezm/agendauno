# ADR 0032 — Cargo del SaaS estable y explicable (fase 1, punto 1.6)

Estado: Aceptado (2026-09-26). Ajusta el ADR 0019 (cobro por modalidad, mes vencido).

## Contexto

El cargo mensual se generaba el día 1 a las 02:00 UTC, que en CDMX todavía es el mes
anterior: el mes no se daba por cerrado, la medición no se congelaba y se cobraba
antes del cierre. Volver a correr el proceso recalculaba y SOBRESCRIBÍA un cargo
pendiente (aunque tuviera un pago en curso), con la tarifa vigente hoy y la prueba
gratis actual, así que publicar una tarifa o extender una prueba cambiaba cargos ya
emitidos. Además se podía emitir el cargo de un mes aún abierto.

## Decisiones

- **Solo meses cerrados** en la zona horaria de cada negocio. Antes del cierre lo que
  hay es la estimación del apartado de renta. El proceso corre a diario a las 02:00 de
  CDMX (08:00 UTC) y, por defecto, emite para cada negocio su mes anterior (en su
  zona) si aún no existe; un periodo que no ha cerrado se salta.
- **Inmutable**: un cargo emitido no se vuelve a calcular. Reejecutar el proceso, una
  tarifa nueva o una prueba extendida no lo cambian.
- **Tarifa del periodo**: la vigente al cierre del mes cobrado (una versión publicada
  después no cambia meses anteriores; antes de la primera versión rige la primera).
- **Con qué se calculó**: el cargo guarda la medición congelada (`medicion_id`), la
  versión de la regla que decide quién cuenta (`regla_version`), la de la tarifa
  (`tarifa_version`, ya existía) y cuándo se emitió (`emitido_en`). El detalle del
  cargo los muestra; "quién cuenta" acepta el periodo para explicar un mes cerrado.

## Consecuencias

- Corregir un cargo emitido requiere una nota de ajuste (fuera de este punto), no
  recalcularlo.
- Reintentos automáticos y suspensión por falta de pago de la renta siguen siendo
  manuales (pendiente).
