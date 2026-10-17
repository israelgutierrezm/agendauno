# ADR 0157 — Cortes de nómina: cerrado, pagado y ajustes aparte

Estado: Aceptado (2026-10-17). Completa el ADR 0153, que dejaba pendiente cerrar
cortes.

## Contexto

La nómina se calcula al vuelo (ADR 0153). Sirve para ver lo devengado, pero no para
pagar con control: nada decía qué periodo ya se pagó, cuánto, quién lo cerró ni con qué
referencia. Una asistencia corregida después cambiaba en silencio lo que «se le debía»
a alguien por un periodo ya pagado.

## Decisión

- **Estados**:
  - Abierto: no se guarda, se calcula al vuelo como hasta ahora.
  - Cerrado (`cortes_nomina`): la foto de lo devengado por persona y tarifa
    (`lineas_corte_nomina`), con el total, la moneda del negocio y quién lo cerró y
    cuándo.
  - Pagado: quién registró el pago, cuándo y su referencia (transferencia, cheque…).
- **Reglas** (`CortesDeNominaTenant`):
  - Solo se cierra un periodo que ya terminó (hasta ayer o antes, en días del negocio).
    Así no queda nada «por venir» dentro del corte.
  - Dos cortes no se enciman. La revisión y el alta van bajo un candado del negocio
    (`Cache::lock`, en Redis en producción), para que dos cierres a la vez no se
    enciman.
  - Un periodo cerrado se puede reabrir mientras no esté pagado: se borra el corte y
    vuelve a calcularse al vuelo.
  - Uno pagado no se reabre. Lo que cambie después va en **ajustes** (`ajustes_nomina`):
    a favor o en contra de una persona, con motivo y quién lo registró.
  - Los ajustes no cambian el corte: se muestran aparte, junto con el total con ajustes.
  - Antes de pagar no hay ajustes: basta con reabrir y volver a cerrar.
- **Diferencias**: el detalle de un corte compara lo que quedó en él con lo que da hoy
  el cálculo del mismo periodo, por persona. Desde ahí se puede crear el ajuste. La
  comparación no cambia nada.
- **Bitácora** (categoría Equipo): cerrar, registrar el pago, reabrir y ajustar.
- **Permisos**: `estudio.gestionar` y la función de plan `comisiones`, igual que la
  nómina.

## Consecuencias

- Lo que se pagó queda fijo y trazable. Un cambio posterior se ve como diferencia y se
  decide con un ajuste.
- El pago se registra; no se dispersa. La transferencia la hace el negocio fuera de la
  plataforma.
- Los cortes no se enciman, pero puede haber huecos entre ellos (días sin cerrar). La
  pantalla los deja ver por fecha.
