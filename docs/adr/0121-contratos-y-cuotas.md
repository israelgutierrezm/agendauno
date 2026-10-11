# ADR 0121 — Contratos y cuotas: inscripción, anualidad, permanencia y baja

Estado: Aceptado (2026-10-10). Plan de mejoras de captación, B04 (bloque 6). Decisiones del
dueño del 2026-10-10.

## Contexto

El caso Grecon (y muchos gimnasios y academias) distingue cuatro cosas:

- el paquete mensual;
- la membresía con permanencia;
- la inscripción;
- la anualidad.

El sistema solo vendía productos que dan clases. Toda línea de orden necesitaba un
producto, así que no había cómo cobrar una cuota. Un acuerdo no guardaba permanencia ni
condiciones aceptadas, y no había forma de dar de baja solo una membresía: solo con la baja
de la persona o un reembolso. Registrar un cargo a mano no resuelve esas reglas.

## Decisión

**Cuotas como líneas de concepto.**

- Una línea de orden puede no tener producto: lleva un `concepto`.
  - `inscripcion`
  - `anualidad`
  - `penalizacion_baja`
  - `adeudo_mensualidad`
- Si la generó el sistema, lleva una `referencia` única que impide cobrarla dos veces.
- Se cobra por la caja y el pago en línea de siempre. No da clases ni crea un acuerdo.
- Recibos, por cobrar, pasarelas, exportación y reportes la nombran por su concepto
  (`LineaOrdenTenant::nombre()`).
- Se descartó modelarlas como productos de un tipo «cuota»: se colarían en los catálogos
  vendibles (página, cuenta, caja) y un error ahí sería silencioso.

**Configuración** (`configuracion_cuotas`, una fila por negocio; `GET/PUT /cuotas`):

- el monto de la inscripción y tras cuántos meses sin plan se vuelve a cobrar;
- el monto de la anualidad y si va en el aniversario de la alta o en una fecha fija
  (mes y día).

Un monto en 0 no se cobra. Cada plan dice si cobra inscripción y si cobra anualidad.

**Inscripción.**

- Al vender un plan que la cobra, se suma si es la primera alta o si regresa tras más de X
  meses sin plan (`CuotasTenant::debeInscripcion`).
- Quien tiene un plan en vigor no la paga. Si ya tiene una por cobrar, no se suma otra; la
  persona se bloquea para que dos ventas simultáneas no la cobren dos veces.
- La promoción no la toca.
- La ven antes de cobrar la cuenta del alumno (`/mi/productos`) y caja
  (`GET /miembros/{persona}/cuotas`).

**Anualidad.**

- `agendauno:generar-cuotas` corre a diario. Quien tiene un plan en vigor que la cobra la
  paga una vez al año, como cuota por cobrar; no se carga sola a la tarjeta.
- Aniversario: en la fecha de su alta, la primera al cumplir un año. El 29 de febrero se
  cobra el 28 en los años no bisiestos.
- Fecha fija: en la fecha del negocio, si ya estaba de alta ese día.
- La referencia (persona y año) evita duplicados. Se pone al corriente dentro de 7 días y
  no cobra de golpe las fechas anteriores a que se activara.

**Exentar** inscripción, anualidad o penalización requiere el permiso nuevo
`cuotas.exentar`. Se puede al vender o sobre una cuota por cobrar
(`POST /ordenes/{orden}/lineas/{linea}/exentar`). Queda en la bitácora
(`cuota.exentada`) con el motivo.

**Permanencia.**

- Una membresía puede pedir permanencia mínima (meses) y decir qué se paga si se da de baja
  antes:
  - `sin_penalizacion`;
  - `monto_fijo` (con su monto);
  - `meses_restantes` (las mensualidades que falten, al precio vigente: también las
    renovaciones usan el vigente, por decisión del dueño).
- Se compra aceptando sus condiciones (`CondicionesDePlan`): el cliente en su cuenta, o el
  equipo registra que el cliente las aceptó (`acepta_condiciones`). Sin aceptarlas responde
  422 `TERMS_NOT_ACCEPTED`.
- La línea guarda la constancia: texto, versión (huella), cuándo, por dónde, quién e IP.
- El acuerdo congela lo pactado: `permanencia_hasta`, `baja_anticipada`,
  `penalizacion_minor` y `condiciones`. Editar el plan no cambia lo vendido.
- Una pausa corre la permanencia los días que dura.

**Baja de una membresía** (`GET/POST /acuerdos/{acuerdo}/baja`, `BajaDeMembresiaTenant`).
Primero la simulación, luego la baja con la misma cuenta.

- Fecha efectiva: al terminar el periodo pagado. Si el cobro ya venció (mora), hoy.
- Penalización: solo si no cumplió la permanencia, según lo pactado.
- La mensualidad vencida no se perdona en silencio. Se cobra aparte («Mensualidad
  pendiente») sin reabrir la renovación, salvo que el negocio la anule.
- Ya no se renueva:
  - queda sin próximo cobro;
  - la renovación por cobrar se anula;
  - el cobro automático se desactiva.
- Sus planes valen hasta la fecha efectiva; las reservas posteriores se cancelan.
- No se abre un ciclo después de la vigencia de un derecho (regla general en
  `GenerarCicloEntitlementTenant`).
- `bajas_acuerdo` guarda la base del cálculo y quién la hizo.
- El proceso diario pasa el acuerdo a cancelado cuando llega su fecha.
- Terminar una membresía es distinto de cancelar una reserva.

## Consecuencias

- Un negocio cobra inscripción y anualidad sin cargos manuales y sin dar clases por ellas.
- El cliente ve el cálculo antes de aceptar (al comprar y al dar de baja). El negocio ve qué
  se cobró, qué se exentó y por qué.
- La fórmula funcional no valida el contrato: la redacción legal y la legalidad de cargos y
  penalizaciones requieren revisión profesional antes de usarse con clientes.
- Un plan sin permanencia ni cuotas se vende igual que antes.
