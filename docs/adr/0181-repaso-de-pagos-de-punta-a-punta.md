# ADR 0181 — Repaso de pagos y finanzas de punta a punta

Estado: Aceptado (2026-10-20).

## Contexto

Se revisó el dinero de punta a punta en todos los roles, en la web y en la app: venta y
cobro en caja, pago en línea con las tres pasarelas, pago automático, devoluciones,
contracargos, caja, facturación, renta de la plataforma, cuotas, promociones, créditos y
nómina. Salieron hallazgos de severidad alta, media y baja. Este registro junta las
decisiones que cambian cómo funciona el sistema; los arreglos puntuales viven en el
código y sus pruebas.

## Decisión

### Venta y cobro

- **Vender y cobrar es una sola operación.** `POST /ordenes` acepta `liquidar` (forma,
  sede y `clave`) y crea y cobra en una transacción. Si el cobro no pasa, no queda una
  orden por cobrar. La `clave` se guarda como `idempotency_key` del pago (`venta:<clave>`):
  repetir la misma venta devuelve la que ya se hizo.
- **La llave de un cobro es de su orden y su pasarela.** Repetirla en otra orden responde
  error y se vuelve a revisar con la orden bloqueada.
- **La compra del alumno desde su cuenta acepta `idempotency_key`** (`ordenes.clave`,
  migración 000173): repetirla (el negocio tardó en responder) devuelve la misma orden.
  La app la manda por intento.
- **Formas de pago cerradas**: el cobro en caja y el del mostrador solo aceptan las
  formas de la caja (o mixto); en línea, las de su pasarela.

### Cuotas y promociones

- **Una cuota exentada queda registrada** (`cuotas_exentadas`, migración 000172): la
  anualidad exentada no se vuelve a cobrar aunque se borre la línea de la orden.
- **El tope de usos de un código cuenta las compras por pagar de las últimas 24 horas.**
  Varias órdenes a la vez ya no lo rebasan al pagarse. Anular un cobro devuelve su uso.
- Una promoción vale **todo su último día en la zona del negocio**.

### Pagos en línea

- **Un cobro de más se registra, nunca se pierde.** Si Mercado Pago aprueba otro cobro
  de una preferencia ya pagada (la tarjeta y luego el ticket de OXXO), queda como un pago
  aparte de la misma orden, en «cobro doble» para devolverlo. Al confirmarse un cobro se
  cancelan los demás pendientes de su preferencia.
- **Los avisos ubican el pago primero por la referencia del cobro** y después por la
  referencia del intento. Así llegan los contracargos y las devoluciones de los cobros
  del pago automático de Mercado Pago y OpenPay.
- **Al confirmar un pago se bloquea primero la orden y después el pago**, como en la caja.
- Si se cobra más de lo que ya debe la orden (se exentó una cuota después de abrir el
  pago), el excedente abre una incidencia para devolverlo.
- **Pago automático**: antes de cobrar la renovación se busca en la pasarela un cargo
  anterior de esa orden que sí se hizo y no quedó registrado; si existe, se registra en
  lugar de cobrar otra vez. El aviso de un cargo automático espera al cargo en curso con
  la orden bloqueada.

### Devoluciones y contracargos

- **Solo dinero, sin tocar lo vendido**, cuando la venta la pagó otro cobro (cobro doble)
  o es una renovación (el periodo ya corre; para quitarlo se pausa o se da de baja). Se
  guarda lo que se pidió (`revertir_pedido`) para que repetir la misma llave sea la misma
  devolución.
- **La reversión parcial cuenta solo las devoluciones que revirtieron**: una compensación
  o un contracargo no hacen que la siguiente revierta de más. **Devolver todo en partes,
  sin uso, es una devolución total**: se retira el plan y se cancela la venta.
- Una devolución en línea que se confirma cuando el plan ya se usó retira lo libre,
  respeta lo apartado y abre una incidencia.
- **Una devolución pendiente sin aviso en 48 horas pasa a «por conciliar»**, donde se
  cierra a mano. El aviso de Stripe que llega antes de guardar la referencia encuentra la
  devolución por su llave. Una devolución que falla después de confirmada abre incidencia
  sin deshacer nada sola.
- **No se devuelve un pago con contracargo abierto**: si se pierde, el banco ya le
  devuelve el dinero al cliente.

### Caja

- **Anular o corregir un cobro solo ajusta la caja si ese cobro entró por una caja.**
  Uno anterior a la caja no la descuadra.
- **Los retiros e ingresos de efectivo llevan su clave** (`movimientos_caja.clave`,
  migración 000173): reintentar no los registra dos veces.
- El conteo admite montos negativos fuera del efectivo.
- Quien anula, corrige o devuelve en caja necesita también la sede de la caja donde entró
  el cobro.
- El corte une la transferencia del mostrador y la SPEI de los cobros, como la caja.

### Créditos y ciclos

- **Al avanzar ciclos solo se concede el cupo de los que no han terminado**: reactivar
  una membresía no regala los meses que pasaron sin correr.
- **Al cerrar un ciclo no vence lo apartado para clases que ya empezaron en él.**
- Las clases extra solo valen con su paquete activo, y su baja cancela también sus
  reservas posteriores.
- Un plan deshecho (cobro anulado o devuelto completo) no cuenta como «tuvo plan» para la
  inscripción.
- Los puntos por asistencia se dan una vez por reserva y se retiran al corregirla.

### Facturación

- **Cada CFDI lleva una llave de idempotencia de FacturAPI** (`idempotency_key`), armada
  con la huella de lo que se pide (`facturas.huella`, migración 000173) y cuántas facturas
  iguales ya se timbraron. Si FacturAPI no responde a tiempo, se responde
  `INVOICING_NO_RESPONSE` (503) y el reintento recibe la misma factura en lugar de timbrar
  otra. La factura de la renta usa el cargo y la huella.

### Renta de la plataforma

- **Extender una prueba que ya terminó regala solo la extensión**: se guarda el hueco
  (`prueba_termino_antes`, `prueba_extendida_desde`, migración de plataforma
  2026_10_20_000100) y esos días se cobran.
- **Se emiten los meses cerrados que faltan** (hasta tres atrás), p. ej. los de un negocio
  que estuvo suspendido.
- Antes de abrir otro pago se busca en Stripe el cobro a la tarjeta domiciliada que quedó
  sin respuesta. Una sesión solo cuenta como cerrada si quedó vencida; al pagarse con
  otro intento, la abierta se vence. Un segundo cobro del mismo cargo alerta al
  superadmin para devolverlo.
- El resumen del superadmin cuenta el mes en la hora de la plataforma.
- El recibo de un plan anual o de un ajuste dice el rango que cubre; la cuota fija de un
  negocio fuera de México no lleva IVA. Una devolución o disputa de un cobro de la
  plataforma (`charge.refunded`, `charge.dispute.created`) alerta al superadmin.
- **La medición de alumnos activos cuenta a quien se archivó o dejó de ser facturable
  durante o después del mes** (`personas.archivado_en`, `no_facturable_desde`,
  migración 000174), como ya contaba las bajas.

### Reportes

- Los indicadores de Cobranza cuentan como el corte: lo cobrado por el día en que se
  cobró, incluido lo que después se devolvió; lo devuelto, por el día en que se devolvió.
- El desglose por producto de Tendencias suma lo vendido: las citas y clases sueltas por
  su servicio, los descuentos en un renglón negativo y lo devuelto en «Devuelto».
- En el corte, «Por cobrar» se llama «Compras del rango sin pagar»: el «Por cobrar» del
  Inicio y de Cobranza es todo lo que se debe hoy.
- Se acepta que recepción pueda deducir lo esperado de la caja con los listados de ventas
  del día: el conteo a ciegas evita errores, no es una barrera de seguridad.

### Alcance por sede (R19)

Quien está acotado a sedes no se asigna otras ni da roles con más permisos que los
suyos, y ve solo las incidencias, la mora, las renovaciones y las campañas de sus sedes.
El cobro de una cita se autoriza por la cita (su sede y su profesional).

## Consecuencias

- Migraciones: de negocio 000172 (`cuotas_exentadas`), 000173 (`facturas.huella`,
  `movimientos_caja.clave`, `ordenes.clave`) y 000174 (`personas.archivado_en`,
  `no_facturable_desde`); de plataforma `2026_10_20_000100_hueco_de_prueba`.
- La web y la app mandan claves por intento al vender, al mover efectivo y al comprar un
  plan.
- Las decisiones que quedaban pendientes las tomó el dueño (ver abajo).

## Decisiones del dueño (2026-10-20)

- **Cita con anticipo que se cancela tarde o no se atiende: solo el anticipo.** El negocio
  conserva el anticipo; lo demás que el cliente ya pagó se le devuelve. Lo pagado en línea
  se devuelve solo, por la misma pasarela (`DevolverPagoDeCitaConAnticipo`, que reemplaza a
  `DevolverAnticipoAlCancelarATiempo`, con llaves `cancelacion-tardia:` e `inasistencia:`
  por reserva y pago); lo pagado en caja lo devuelve el negocio, y la vista previa de la
  inasistencia lo dice (`devuelve_minor`, `devuelve_en_linea_minor`). Cancelar a tiempo
  sigue devolviendo todo.
- **Cortesías con permiso y motivo.** `POST /acuerdos` da un plan sin cobrarlo: pide el
  permiso nuevo `membresias.cortesia` (el propietario y el administrador lo traen;
  recepción no) y un `motivo`, que queda en la bitácora como `membresia.cortesia`.
- **Lo pactado con cada negocio, con fecha de vigencia.** El superadmin dice desde qué mes
  rige el cobro por uso o una cuota fija (`vigente_desde`, AAAA-MM; por omisión, el primer
  mes sin cobrar). Cada mes se cobra con lo que regía a su cierre (`condiciones_cobro_saas`,
  migración `2026_10_20_000300`); un mes ya cobrado no cambia (422) y se pacta a lo más 12
  meses adelante. Las columnas de `estudios` quedan con lo que rige hoy: se actualizan al
  pactar y cada día con la renta.
- **Techo de la tarifa de clases.** Versión nueva de la tarifa (migración
  `2026_10_20_000200_techo_de_clases`): 301–500 alumnos activos a USD 149 y 501–1,000 a
  USD 199; las demás bandas, las versiones anteriores y los cargos ya emitidos no cambian.
