# ADR 0096 — Días del negocio, aniversarios sin desborde y dinero por moneda

Estado: Aceptado (2026-10-05).

## Contexto

Una auditoría encontró siete fallas; cuatro tocan reglas que se repetían en varios
lugares con criterios distintos:

- **Fechas.** Unas partes comparaban `valido_hasta` (una fecha sin hora) contra el día
  en UTC, otras contra la zona del negocio. En la Ciudad de México (UTC−6) un plan que
  vence el 15 dejaba de valer el 15 a las 18:00 locales, y las tareas diarias
  (`reanudar-pausas`, `generar-ciclos`) corrían a medianoche UTC.
- **Aniversarios.** Un ciclo mensual por aniversario se calculaba con
  `addMonth()->subDay()`: un plan que empieza el 31 de enero desbordaba a marzo (ciclo
  hasta el 2 de marzo, cobro el 3), y cada renovación arrastraba el corrimiento.
- **Dinero.** El reporte de negocio sumaba órdenes pagadas de monedas distintas, por la
  fecha de la compra, sin mostrador ni devoluciones parciales; Cobros calculaba otra
  cosa.
- **Sucursal.** Los movimientos de dinero (lista, totales, corrección y anulación) no
  respetaban el alcance por sucursal del personal (R19).

## Decisión

- **Días del negocio** (`FechasNegocioTenant`): toda fecha sin hora (vigencias, ciclos,
  pausas, próximo cobro, «hoy» de reportes) es un día local en la `zona_horaria` del
  negocio. Una vigencia `[desde, hasta]` cubre hasta el último segundo local de
  `hasta`. Es la única fuente de «hoy» para esas reglas.
- **Tareas de cambio de día**: `reanudar-pausas` y `generar-ciclos` corren cada hora
  (son idempotentes), así cada negocio las procesa al empezar su día, sea cual sea su
  zona. `cobrar-suscripciones` sigue una vez al día (es la cadencia de los reintentos)
  a las 00:45 de la Ciudad de México.
- **Aniversarios** (`Membresias\Aniversario`): cada acuerdo guarda su día ancla
  (`acuerdos.dia_ancla`): el de su inicio por aniversario, el 1 por calendario. El
  siguiente aniversario es ese día del mes o, si el mes no lo tiene, su último día; el
  mes siguiente vuelve al día ancla (31 ene → 28 feb → 31 mar). Un ciclo va del
  aniversario al día anterior al siguiente. La misma regla se usa al activar, al
  renovar el ciclo y al registrar el pago de la renovación; una pausa corre el ancla
  junto con las fechas.
- **Dinero por moneda** (`MovimientosDePagoTenant::porMoneda`): nunca se suman monedas.
  Por moneda: vendido (compras no canceladas y mostrador, por su fecha), cobrado (por
  la fecha del cobro, con mostrador), devuelto (por la fecha de la devolución, también
  parciales) y neto. El reporte de negocio usa ese cálculo; `ingresos_minor` es el neto
  de la moneda principal y `dinero_por_moneda` trae el detalle.
- **Sucursal en el dinero**: los movimientos, sus totales, el reporte y la corrección o
  anulación de un cobro se limitan a las sucursales del actor
  (`ResolverAccesoTenant::sucursalesPermitidas`); un cobro de otra sede responde 403.

## Consecuencias

- Las pruebas que simulaban «después de medianoche» en UTC ahora viajan a la
  medianoche local del negocio.
- La migración `000110` llena `dia_ancla` de los acuerdos existentes con el día de su
  próximo cobro (respeta pausas), salvo aniversarios del 29 al 31, que vuelven al día de
  su inicio; de calendario, el 1.
- Queda pendiente llevar moneda y sucursal a Tendencias y Cohortes.
