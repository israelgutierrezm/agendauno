# ADR 0043 — Tolerancia de inasistencias

Estado: Aceptado (2026-09-26). Complementa el R8 (política congelada), el ADR 0034
(asistencia y créditos) y el ADR 0042 (parámetros configurables).

## Contexto

La política de cancelación guardaba `tolerancia_no_show` ("no-shows tolerados antes
de sanción"), pero nada la aplicaba: con "cobrar inasistencias", la primera falta ya
cobraba el crédito. Muchos estudios perdonan una o dos faltas al mes antes de cobrar.
Ese límite debe configurarse, no quedar fijo.

## Decisiones

- **Qué se configura**: cuántas faltas se toleran (`tolerancia_no_show`, 0 = ninguna)
  y en cuántos días a la redonda se cuentan (`ventana_no_show_dias`).
  - El negocio lo ajusta en su política, general o por actividad, en Reglas de la
    agenda.
  - Si la política no fija la ventana, aplica la de la plataforma.
  - Los negocios sin política propia usan lo que fija el superadmin
    (`cancelacion.tolerancia_no_show` y `cancelacion.ventana_no_show_dias`). La pantalla
    parte de esos valores.
- **Cómo se aplica**: al marcar una falta (con "cobrar inasistencias"):
  - si la persona lleva menos faltas que la tolerancia en la ventana (sin contar esa
    reserva), el crédito regresa;
  - si no, se cobra.
  - Se cuentan todas sus faltas en la ventana, toleradas o no.
  - La política de tolerancia se lee al marcar (es sobre el historial de la persona).
    Las horas de cancelación y los cobros siguen congelados en la reserva (R8).
- **Correcciones sin descuadre**: cada asistencia guarda si cobró el crédito
  (`credito_cobrado`), así corregir llegó ↔ no vino compensa según lo que realmente
  pasó. Una falta tolerada corregida a "llegó" cobra; volver a "no vino" la
  reevalúa.

## Consecuencias

- Las faltas toleradas no dejan movimiento en el historial de créditos (el crédito
  nunca salió).
- Bloquear reservas por exceso de faltas sería una sanción distinta; queda fuera por
  ahora.
