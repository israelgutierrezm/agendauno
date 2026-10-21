# ADR 0137 — Permanencia por porcentaje, una sola clase de prueba y continuidad del prospecto

Estado: Aceptado (2026-10-11). Pendientes de la revisión del dueño previa a la V1
(«Al terminar checa los siguientes puntos»). Decisión del dueño sobre la permanencia:
«Sí, porcentaje configurable».

## Contexto

La revisión dejó cinco puntos que no caben en un ADR propio cada uno:

1. **Permanencia (B04, ADR 0121).** Con «meses restantes», quien da de baja antes
   pagaba todas las mensualidades que faltaran. Grecon cobra el 50 %.
2. **Clase de prueba (B03).** La regla «una sola vez, solo para quien nunca vino» se
   revisaba al crear la orden y antes de cobrarla, pero:
   - una orden podía traerla en dos líneas (cantidad 1 cada una);
   - dos órdenes pendientes cobradas por la pasarela casi a la vez (sin pasar por la
     revisión de la caja) concedían dos.
3. **Continuidad de captación (B01/C04, ADR 0117).** El prospecto deja lo que le
   interesa (una clase, un plan). Al convertirse y entrar a su cuenta, tenía que
   buscarlo de nuevo.
4. **Analítica de activación.** `tenant_activated` se emitía en toda activación de
   cuenta: también la de un instructor o un cliente invitados, que no son un negocio
   nuevo.
5. **Importador (ADR 0125).** La agenda futura del sistema anterior no se importa. La
   pantalla lo decía solo con palabras de clases.

## Decisión

**1. Permanencia por porcentaje.**

- Con «meses restantes», el plan guarda `penalizacion_porcentaje` (1–100; vacío =
  100). Se edita en Planes junto a la permanencia.
- Se congela en cada membresía vendida (`acuerdos.penalizacion_porcentaje`), como el
  resto de las condiciones: cambiar el plan después no cambia lo que aceptó.
- La penalización es meses que faltan × mensualidad × porcentaje, redondeada al
  centavo. La simulación de baja devuelve `porcentaje` y la pantalla muestra la
  cuenta completa.
- Al 100 % el texto de las condiciones es el de siempre, para no cambiar la versión de
  lo ya aceptado. Con otro porcentaje dice «pagas el N % de las mensualidades que
  falten».

**2. Una sola clase de prueba, también con pagos simultáneos.**

- Al crear la orden: una por persona, aunque venga en varias líneas.
- Al cumplir una orden pagada, la elegibilidad se decide **antes** de conceder nada
  de esa orden, porque otra línea suya (un plan) crearía una membresía y la haría
  parecer usada. Se decide con la persona bloqueada (`aunElegible`), dentro de la
  transacción del pago.
- Si ya no es elegible (otra orden pagada a la vez ya se la dio), esa línea no se
  concede. El pago queda en «Por conciliar» como `primera_vez_repetida`, para
  devolverlo o venderle otro plan. Nunca se concede una segunda.

**3. Continuidad del prospecto.**

- `GET /mi/interes` devuelve lo que pidió como prospecto (el plan y la clase de su
  ficha de captación). Solo se devuelve:
  - durante 30 días después de convertirse (o mientras la invitación esté reciente);
  - si sigue disponible: el plan no archivado, la clase programada y futura;
  - si aún no lo tiene: el plan sin comprar, la clase sin reservar.
- El Inicio del portal lo muestra junto a «Lo que pide atención», con enlace a
  reservar o a comprar. No aparta lugar ni crea nada. Si falla, no se muestra.

**4. Activación.**

- `tenant_activated` solo cuando quien activa es el propietario: es un negocio
  nuevo.
- Para el equipo o un cliente invitados se emite `account_activated` con su faceta.

**5. Importador.**

- La pantalla de importación dice, con las palabras de cada modalidad, que las
  reservas o citas futuras no se copian y se agendan después del corte.

## Consecuencias

- Grecon se configura con `meses_restantes` y 50 % (ver `docs/DEMO_GRECON.md`).
- Migración de negocio 000129 (`productos_comerciales` y `acuerdos`). Las membresías
  vendidas antes quedan sin porcentaje, es decir, al 100 %, como las aceptaron.
- Un cobro de pasarela de una clase de prueba repetida ya no da una segunda; deja una
  incidencia que alguien debe resolver.
- Las métricas de activación cuentan negocios, no cuentas.

## Ajuste (2026-10-21)

- Si una orden con un plan y una clase de prueba ya usada se paga, se concede el plan.
  La incidencia «por conciliar» propone devolver solo lo de la clase de prueba
  (`excedente_minor`, con su parte del descuento), no todo el pago.
