# ADR 0219 — Comisión en porcentaje, propinas y «Mis comisiones»

Estado: Aceptado (2026-10-26). Completa el ADR 0153 (nómina) y el 0157 (cortes).

## Contexto

La nómina pagaba por clase, por asistente o por hora: lo de los negocios de clases. En
una barbería o un salón lo común es que el profesional gane un porcentaje de lo que
cobra, más sus propinas, y que pueda ver cuánto lleva. La propina ya se cobraba con la
cita, pero no se le asignaba a nadie. El dueño lo pidió para la V1.

## Decisión

- **Comisión en porcentaje** (`TipoPago::PorcentajeVenta`), solo en negocios de citas.
  Se guarda en puntos base (`porcentaje_bp`, 4000 = 40 %) en el esquema y en cada
  versión de la tarifa (con las mismas reglas de versiones y cortes de siempre).
  - Lo devengado es el porcentaje de lo cobrado por cada cita atendida: sus órdenes
    pagadas (la del servicio y la del saldo de un anticipo), el precio del servicio
    pagado por adelantado (ADR 0218) o, si se tomó con su bono, el precio del servicio.
  - La proyección usa el precio del servicio de las citas confirmadas que faltan.
  - Los reportes de rentabilidad usan el mismo porcentaje sobre lo que dejó la sesión.
- **Propinas**: las cobradas con sus citas (las ventas no anuladas) van en una fila
  propia de la nómina (`tipo` = `propinas`), tenga o no esquema; entran en lo devengado
  y en el corte.
- **Mis comisiones** (`GET /mis-comisiones`, con la función de comisiones del plan):
  quien atiende ve solo lo suyo del periodo (ganado, propinas y por venir). En la web
  es una pantalla de su menú en los negocios de citas; en la app, un acceso de su
  Inicio (`InstructorScreen`) con la misma regla (citas y la función del plan).
