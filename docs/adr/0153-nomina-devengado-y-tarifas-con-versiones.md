# ADR 0153 — Nómina: devengado, proyección y tarifas con versiones

Estado: Aceptado (2026-10-17).

## Contexto

La nómina (R17) calculaba al vuelo, y eso tenía tres problemas:

- Sumaba las sesiones futuras del periodo que no estuvieran canceladas. Su «Total a
  pagar» parecía definitivo aunque incluyera clases que aún no se daban.
- Usaba el esquema de pago actual de cada quien, también al consultar periodos
  anteriores. Cambiar una tarifa cambiaba lo que «se le debía» por meses ya
  trabajados.
- Tomaba los límites del periodo sin la zona horaria del negocio. Una clase a las 19:00
  de la Ciudad de México caía en el día siguiente.

Los reportes de equipo y de rentabilidad (`ValorDeSesionesTenant`) tenían el mismo
problema de la tarifa actual.

## Decisión

- **Devengado contra proyección.**
  - `monto_total_minor` es solo lo ya impartido: la sesión terminó.
  - Lo que falta del periodo va aparte, como proyección: `unidades_por_venir` y
    `por_venir_minor`. Por asistente, la proyección se calcula con las reservas
    confirmadas.
  - `al_cierre_minor` suma lo devengado y lo proyectado.
  - La web dice «Devengado» y «Por venir», y en los indicadores «Devengado a la fecha» y
    «Proyección al cierre del periodo».
- **El periodo son días del negocio**, en su zona horaria (`FechasNegocioTenant`).
- **Tarifas con versiones** (`esquemas_pago_versiones`):
  - Cada versión vale desde un día del negocio. `vigente_desde = null` significa desde
    siempre.
  - Guardar un esquema crea una versión desde hoy (o desde `vigente_desde`). La primera
    versión de cada quien vale desde siempre. Las tarifas existentes quedaron como su
    primera versión.
  - `TarifasDePagoTenant` da la tarifa de cada sesión: la vigente el día en que empezó.
    La usan la nómina y los reportes.
  - Si la tarifa cambió dentro del periodo, la nómina devuelve una fila por tarifa. La
    web las junta en una fila por persona y avisa desde cuándo vale la nueva.
  - `esquemas_pago` sigue guardando la tarifa vigente, con su `activo`.

## Consecuencias

- Consultar un mes pasado da lo mismo aunque después cambien las tarifas.
- Lo que se paga sale de lo devengado; la proyección es solo informativa.
- Pendiente, si se necesita: cerrar cortes. Un corte cerrado congelaría un periodo ya
  pagado aunque después se corrija la asistencia.
