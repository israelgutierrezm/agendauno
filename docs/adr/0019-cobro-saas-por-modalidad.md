# ADR 0019 — Cobro del SaaS por modalidad

Estado: Aceptado (2026-09-22)

## Contexto

AgendaUno cobraba a cada negocio "alumnos activos × precio por alumno", donde
"activo" era cualquier alumno dado de alta. Eso no sirve para un negocio de citas
(barbería, salón, salud), cuyo tamaño lo da el número de profesionales, y cobraba
por alumnos sin actividad (incluidos clientes de una sola cita). Tras investigar
el mercado (reservaclase por alumnos, agendapro por profesional), el dueño del
producto decidió: la modalidad se deriva del giro (ADR 0018), regla híbrida para
negocios mixtos y precios 10% por debajo de la competencia.

## Decisiones

- **Métrica por modalidad** (`MedirUsoSaas`, mes calendario en la zona del estudio):
  - Clases → **alumnos activos** (`alumnos-v2`): alumnos facturables, no archivados,
    con una reserva confirmada en una sesión no cancelada o una compra pagada en el
    mes. Quien no usó ni pagó ese mes no cuenta.
  - Citas → **profesionales activos** (`profesionales-v1`): quienes atendieron al
    menos una sesión no cancelada en el mes. Con menos de `horas_medio_tiempo`
    (20 h) de atención semanal cuentan como medio tiempo (0.5). Cantidades en
    milésimas, nunca float. *Reemplazado por el ADR 0094: cada profesional cuenta
    completo, sin medio tiempo (`profesionales-v2`).*
  - Regla híbrida (citas): cada profesional incluye 10 personas atendidas fuera de
    cita (clases/talleres), tope 100; cada persona adicional cuesta $9.
  - El conteo corre en la BD del tenant; al control plane solo sube el agregado.
    Los nombres solo se muestran al dueño ("quién cuenta").
- **Tarifas versionadas** (`tarifas_saas`): una versión por modalidad; publicar
  cambios crea una versión nueva y cada cargo guarda la versión aplicada. v1 (MXN,
  sin IVA): clases por bandas (≤40 $339, ≤80 $639, ≤120 $909, ≤200 $1,359,
  ≤300 $1,789, ≤500 $2,649, techo $2,889); citas marginal por profesional (1º $269,
  2º $226, 3º–10º $135, 11º–20º $89, 21º+ sin costo). Prueba: 30 días clases,
  14 citas. IVA 16% sobre el subtotal; el cargo guarda el total.
- **Cálculo puro** (`CalcularRentaSaas`): desglose con líneas, subtotal, IVA y total,
  guardado con el cargo. Sin actividad no hay cargo.
- **Mes vencido**: el día 1 (`agendauno:generar-cargos-renta`, por defecto el mes
  anterior) se congela la medición del mes cerrado y se genera su cargo. Una
  medición congelada no se recalcula.
- **Prueba gratis**: los días cubiertos por la prueba no se cobran; el mes en que
  termina se prorratea por días. Un periodo con total 0 queda `sin_cargo` (no se
  paga ni se factura).
- **Cuota fija**: el superadmin puede pactar `modo_cobro = fijo` por estudio; el
  antiguo "precio por alumno" deja de usarse.

## Consecuencias

- Las pruebas que asumían "activo = dado de alta" se ajustaron a actividad.
- Pendiente: plan anual (10 meses), módulo clínico (+$179/profesional) y WhatsApp
  por consumo — requieren esos módulos/medidores.
