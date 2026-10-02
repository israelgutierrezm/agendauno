# ADR 0091 — La operación según el tipo de negocio

Estado: Aceptado (2026-10-02). Complementa los ADR 0049 (terminología) y 0088
(configuración inicial).

## Contexto

En una barbería todavía se notaba la lógica de una academia. El Inicio decía «Por
pasar lista», el panel pedía «Define una cita (oferta)» y Ventas abría en
«Membresías y paquetes». Cambiar «clase» por «cita» no alcanza: también tiene que
cambiar la tarea que se propone. Tampoco sirve usar el mismo indicador con otro
nombre cuando mide trabajos distintos.

## Decisión

### El Inicio responde las preguntas de cada tipo de negocio

`GET /inicio/hoy` (`ResumenDelDiaTenant`) devuelve la `modalidad` y, junto con lo
que ya daba, los totales que cada modalidad necesita.

**Citas**

- ¿Quién viene después? Es la tarjeta principal: muestra al cliente y, debajo, el
  servicio, quién lo atiende y la sede.
- ¿Quién ya llegó? → `llegaron`. Cada cita dice «Llegó».
- ¿Qué citas faltan por atender? → `por_atender`: las que no han terminado y tienen
  a alguien que todavía no llega ni falta.
- ¿Qué servicios faltan por cobrar? → `por_cobrar`: citas con su orden pendiente.
  Cada cita lo marca.
- ¿Dónde tengo espacios libres? → `libres`: por profesional y sede, cuántos huecos
  quedan hoy para el servicio más corto y desde qué hora. Usa el mismo cálculo que
  la página de agendar. Un profesional acotado solo ve los suyos.

**Clases**

- ¿Qué clases tengo hoy? → `sesiones`.
- ¿Cuántos lugares están ocupados? → `esperados` de `capacidad`.
- ¿Qué listas faltan por registrar? → `listas_pendientes`: clases, no personas.
- ¿Quién está en espera? → `en_espera`, en total y por clase.
- ¿Qué planes están por vencer? → `renovaciones.por_vencer`.

Cada modalidad tiene sus propios indicadores; no son los mismos con otro nombre.

### Ventas empieza por lo que vende cada negocio

- **Clases:** primero «Membresías y paquetes», después el mostrador y el inventario.
- **Citas:** primero el mostrador y el inventario. Cada servicio ya tiene su precio,
  así que los planes no son la puerta de entrada. «Bonos y membresías» queda al
  final como opción: en una barbería o un spa pueden servir, pero no son lo
  principal.

### Servicios, combos, bonos y membresías en citas

En citas se separan cuatro cosas:

- **Servicio:** una atención, como un corte de cabello. Se da de alta en Catálogo,
  con su duración y su precio.
- **Combo:** varios servicios en una misma visita, como corte y barba. También va
  en Catálogo: es un servicio que incluye otros.
- **Bono de sesiones:** varias visitas prepagadas, como cinco masajes. Es un plan de
  tipo paquete, con sus sesiones.
- **Membresía:** servicios o beneficios recurrentes por una cuota.

Con citas, el editor de planes solo ofrece bono y membresía, y explica que el
servicio suelto y el combo van en Catálogo. Con clases sigue igual: paquete,
membresía, clase suelta, clases extra y otros. Las membresías no se quitan de
barberías ni spas, pero quedan como opción y no como la entrada principal de Ventas.

### «Con bono o membresía» y la reserva pública

Un servicio se paga o se toma con bono o membresía. La página pública solo agenda
servicios con precio. Por eso, cambiar un servicio a «con bono» no es una
preferencia sin consecuencias: el catálogo avisa, al cambiarlo, que deja de aparecer
en la página para agendar, o que vuelve a aparecer con su precio.

Las sesiones se canjean así:

- Desde su cuenta, el cliente ve, además de los servicios con precio, los que se
  toman con bono **si tiene un bono o una membresía vigente, con saldo, que los
  incluya** (`OpcionesCitaTenant::listar($persona)` →
  `ResolverDerechoTenant::ofertasCubiertas`). Esos servicios no muestran precio, y la
  cita se descuenta del bono con el flujo de siempre (`ReservasTenant::crear`, con
  retención en el libro de créditos).
- El negocio también los agenda desde la agenda.
- La página pública recibe `hay_con_plan` para invitar a entrar a la cuenta a quien
  tiene un bono.
- En el editor de planes, un bono solo se puede aplicar a los servicios que se toman
  con bono. Si no hay ninguno, el editor lo dice y lleva a Catálogo.

## Consecuencias

- Los textos del Inicio salen de llaves separadas por modalidad
  (`operacion.hoy.citas.*`, `operacion.hoy.clases.*`), no de la terminología.
- Calcular los espacios libres cuesta unas consultas por profesional y sede; se hace
  solo con citas y solo para el día que se muestra.
- Un servicio no es «de pago» y «con bono» a la vez. Si un negocio vende el mismo
  corte suelto y en bono, hoy son dos servicios. Cobrar con el bono cuando el
  cliente lo tiene y, si no, con el precio, queda como mejora futura.
