# ADR 0129 — El responsable paga con su tarjeta el pago automático de quien tiene a su cargo

Estado: Aceptado (2026-10-11). El dueño decidió que la tarjeta quede a nombre del
responsable. Completa el ADR 0123 (familias), que había dejado este punto fuera.

## Contexto

Con familias, un responsable paga y reserva por personas a su cargo, que muchas veces
no tienen cuenta (un niño de la escuela de natación). El pago automático quedaba fuera:
- desde la cuenta del responsable no se podía activar el de un participante;
- al pagar una compra con «cobrar automáticamente» por un participante, se rechazaba;
- en la renovación de un participante, la tarjeta quedaba a nombre del participante,
  aunque la pusiera el responsable.

## Decisión

**Quién paga.** La domiciliación ya guardaba la persona (`persona_id`). Ahora esa es
quien paga:
- el titular de la membresía, como siempre;
- o un responsable con la autorización vigente por él.

La tarjeta y el cliente en la pasarela son de quien paga. La membresía, sus créditos y
sus avisos siguen siendo del participante. Una persona sigue teniendo una sola tarjeta
por pasarela (Stripe): si la cambia, cambia para todo lo que paga, también lo de sus
participantes.

`FamiliasTenant::participantesAutorizados()` dice por quién puede pagar alguien:
- a su cargo, con la tutela activa (autorizada);
- aún alumnos del negocio;
- con familias encendidas en el negocio.

**En la cuenta del responsable** (`/mi/pago-automatico`, siempre del titular):
- lista también las membresías de sus participantes con `para {id, nombre}` y las
  activa o las quita con su tarjeta;
- la de alguien sin autorización vigente no existe para él (404);
- la web y la app dicen «Para Sofía» y avisan que esas membresías se cobran a su
  tarjeta.

**Al pagar por un participante** (`X-Participante`) se puede marcar «cobrar
automáticamente cada renovación a tu tarjeta». La tarjeta se guarda a nombre de quien
paga:
- en una compra nueva, el comprador ya es el responsable;
- en la renovación del participante (`DomiciliacionesTenant::pagadorDeCompra`), es el
  usuario que la pagó, si es su responsable con la autorización vigente; si no, el
  comprador.

Con Mercado Pago y OpenPay, la suscripción se crea con los datos de quien paga.

**Al quitar la autorización:**
- Retirarla, el responsable o el negocio, cierra al momento los pagos automáticos que
  ese responsable tenía para ese participante. La tarjeta se desliga si ya no paga nada
  más.
- Antes de cada cargo, `sigueAutorizada()` revisa que quien paga aún pueda pagar, por
  ejemplo si el negocio apagó familias. Si no, se quita el pago automático y la
  renovación se avisa para pagarla a mano, como a quien no lo activó.

## Consecuencias

- Una mamá activa una vez el pago automático de las clases de sus hijos, con su
  tarjeta, sin que ellos tengan cuenta.
- La pasarela nunca cobra a nombre del niño. El recibo y el cliente son del adulto que
  paga.
- Retirar la autorización o apagar familias corta los cobros a la tarjeta del
  responsable; lo ya cobrado se conserva.
- Sin migraciones: `domiciliaciones.persona_id` ya existía. Las domiciliaciones de
  antes no cambian: su persona era el titular.
