# ADR 0094 — Cobro por profesional, sin medio tiempo

Estado: Aceptado (2026-10-03). Reemplaza la regla de medio tiempo del ADR 0019.

## Contexto

En los negocios de citas, la renta se cobraba por profesional activo. Si un
profesional tenía configuradas menos de `horas_medio_tiempo` (20 h) de atención a la
semana, contaba como medio tiempo (0.5). El dueño del producto decidió que esa
distinción es incorrecta: el modelo es por profesional, sin importar sus horas.

## Decisión

- **Medición** (`MedirUsoSaas`, regla `profesionales-v2`): cuenta cada profesional
  que atendió al menos una sesión no cancelada en el mes, y cada uno cuenta como uno.
  El horario de atención ya no influye. El detalle de la medición ya no trae
  `fte_milesimas` y «quién cuenta» ya no marca medio tiempo.
- **Cálculo** (`CalcularRentaSaas::citas`): recibe el número de profesionales en
  enteros (antes, equivalentes en milésimas). Los tramos marginales, las personas
  incluidas fuera de cita, su tope y el precio por persona adicional no cambian.
- **Tarifas**: el superadmin ya no captura `horas_medio_tiempo` al publicar una
  versión de citas. Las versiones anteriores que lo traen se conservan sin cambios
  (son versiones publicadas), pero ese campo ya no se lee.
- **Cargos ya emitidos**: no cambian. Guardan su desglose y su regla (`profesionales-v1`).
  Las mediciones de periodos abiertos se recalculan con la regla nueva.
- **Panel del dueño**: la renta muestra cuántos profesionales activos hay, sin
  «equivalentes de tiempo completo».

## Consecuencias

- El precio de un negocio de citas depende solo de cuántos profesionales atendieron
  en el mes. Un profesional que trabaja pocas horas paga como uno completo.
- La landing ya no anuncia un precio de medio tiempo: el precio de entrada es el de
  un profesional.
