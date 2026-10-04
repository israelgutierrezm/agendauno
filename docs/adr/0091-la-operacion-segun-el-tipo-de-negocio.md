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

### La cuenta del cliente

`GET /mi/perfil` (`PortalDelClienteTenant`) dice qué partes de la cuenta le sirven
a cada cliente:

- **Créditos:** aparecen si tiene o tuvo un bono, paquete o membresía, o si el
  negocio trabaja con clases y vende planes.
- **Pase:** aparece si el negocio controla accesos, ya sea por acceso abierto o
  porque registra entradas.
- **Expediente:** aparece si el negocio pide consentimientos o fichas, o si la
  persona tiene documentos.

También devuelve la asistencia de los últimos 30 días, el vencimiento de cada
derecho y cómo llegar a la sucursal de cada reserva.

El Inicio ordena los accesos según el tipo de negocio:

- **Citas:** próxima cita (con «Cómo llegar» y «Cambiar o cancelar») → mis citas →
  volver a agendar → pagos. El bono, el expediente y el pase solo aparecen si
  aplican.
- **Clases:** próxima clase → reservar → mis reservas → clases de su plan (con su
  vencimiento) → asistencia → pagos.

Una tarjeta vacía de «Sin paquete» no le sirve a quien solo quiere cortarse el
cabello, así que no se muestra.

### En la app

La app sigue lo mismo (2026-10-03): la cuenta del cliente ordena y muestra los
accesos con `portal`, la asistencia de 30 días, el vencimiento y «Cómo llegar»; al
agendar, los servicios de su bono aparecen «Con tu bono» y no se pagan; y el Inicio
del equipo usa los indicadores de cada tipo y, en citas, los espacios libres.

## Consecuencias

- Los textos del Inicio salen de llaves separadas por modalidad
  (`operacion.hoy.citas.*`, `operacion.hoy.clases.*`), no de la terminología.
- Calcular los espacios libres cuesta unas consultas por profesional y sede; se hace
  solo con citas y solo para el día que se muestra.
- Un servicio no es «de pago» y «con bono» a la vez. Si un negocio vende el mismo
  corte suelto y en bono, hoy son dos servicios. Cobrar con el bono cuando el
  cliente lo tiene y, si no, con el precio, queda como mejora futura.

## Ampliación: la cuenta dice lo que puede hacer antes de intentarlo (octubre de 2026)

Una revisión del portal del miembro encontró que la cuenta dejaba descubrir las
reglas al fallar (reservar una clase que su plan no incluye) o las deducía en la
pantalla (el Inicio decidía si un plan estaba vigente solo por su vencimiento).
Ahora el servidor lo dice y las pantallas solo lo muestran:

- **Cobertura por clase.** `GET /mi/agenda` trae, por clase, `cobertura`:
  `incluida`, `solo_membresia` (solo una membresía del catálogo la incluye),
  `no_incluida` con su `motivo` (`sin_plan`, `clase`, `pausa`, `suspendido`,
  `vigencia`, `sucursal`, `saldo`) o `de_pago` con su precio. La calcula
  `ResolverDerechoTenant::coberturaDeSesiones` con la misma regla que al reservar
  (plan activo, vigente el día de la clase, de esa actividad, clase y sucursal, con
  saldo), leyendo los planes y su saldo una sola vez. Reservar sigue validando bajo
  bloqueo: la cobertura informa, no autoriza.
- **Estado efectivo del plan.** `GET /mi/perfil` trae el `estado` de cada derecho
  (vigente, agotado, por empezar, en pausa, suspendido, vencido, cancelado) con la
  misma regla del corte de planes (`CorteDePlanesTenant::estadoEfectivo`).
- **Adeudos aparte.** `GET /mi/ordenes/pendientes` trae todo lo que debe, sin tope;
  `GET /mi/ordenes` es el historial paginado (`excluir_pendientes` quita lo
  pendiente). Antes los pendientes salían de las últimas 50 órdenes y uno antiguo
  podía dejar de verse. Cada orden trae su `concepto` y, si es una cita, la
  `sesion` (servicio, profesional, cuándo y dónde).
- **Historial.** `GET /mi/historial` (paginado, con fechas del calendario del
  negocio): lo que tomó, faltó o canceló (él o el negocio), si cambió de horario,
  su reseña o si aún puede calificarla, y lo necesario para volver a reservar lo
  mismo.
- **Celular.** `PUT /yo/perfil` acepta `celular` cuando el usuario tiene ficha de
  cliente o alumno (`tiene_ficha` en `/yo`); es único por negocio.

En la web, Reservas se organiza por tarea (Reservar · Próximas · Historial, con
filtros por actividad, instructor y sucursal) y Pagos por modalidad (en citas,
primero lo que debe y el historial; los bonos solo si los tiene). La app sigue lo
mismo.
