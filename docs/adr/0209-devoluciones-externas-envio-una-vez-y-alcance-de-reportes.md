# ADR 0209 — Devoluciones hechas en la pasarela, envío como máximo una vez y alcance de reportes

Estado: Aceptado (2026-10-24).

## Contexto

Lo que la revisión del API (ADR 0207) dejó como dudas, ya confirmadas:

- Una devolución hecha directo en el panel de Stripe, Mercado Pago u OpenPay no se
  registraba: el pago seguía cobrado, los créditos seguían y los ingresos no bajaban.
- Un reenvío anónimo de captación con el correo o el teléfono de un prospecto abierto
  reemplazaba sus respuestas y su interés.
- Si una pasada del envío de mensajes se cortaba entre entregar un mensaje y anotarlo,
  la siguiente lo volvía a mandar.
- Los reportes de Wellhub, TotalPass y Fitpass, su conciliación y la bitácora no se
  acotaban por sede; una entrada (acceso) se podía registrar en cualquier sede.
- Un contracargo perdido sobre un pago ya devuelto por completo registraba otra
  devolución.
- La expiración de un apartado bloqueaba reserva → orden y la aprobación de su pago
  orden → reserva: con la misma cita a la vez, MySQL cortaba una de las dos.

## Decisión

**Devoluciones hechas en la pasarela.** Se registran como devolución del pago
(`ReembolsarPagoTenant::registrarExterna`, vía `pasarela_externa`), una sola vez por el
id de la devolución en la pasarela:

- Llegan por su aviso: Stripe (`refund.created|updated`, `charge.refund.updated` y
  `charge.refunded`, con las devoluciones del aviso o leídas de Stripe), Mercado Pago (las
  `refunds` del pago al releerlo) y OpenPay (`charge.refunded`, releyendo el cargo).
- Si el aviso no llegó, `agendauno:conciliar-reembolsos` le pregunta a la pasarela
  (`PasarelaConDevoluciones`) por los cobros en línea aprobados de los últimos 30 días,
  cada uno a lo más una vez al día y hasta 50 por pasada.
- Primero se concilia la que pidió la plataforma (por su id o su llave); si quedó sin id
  (incierta, pendiente), la del mismo monto se completa en vez de crear otra.
- Mismo efecto que una devolución desde el panel: el pago queda devuelto (total o en
  parte), cuenta en el corte y los reportes, y lo comprado se revierte (proporcional en
  una parcial; la que deja todo devuelto cancela la venta). En una renovación o un cobro
  doble, solo dinero, como el panel.
- Si el panel no lo habría permitido (lo comprado ya se usó, la cita sigue en pie o
  devolvió más de lo que quedaba), solo cuenta el dinero y queda una incidencia
  `devolucion_externa` para que el negocio decida. En otra moneda, solo la incidencia.

**Captación.** Un envío con el correo o el teléfono de un prospecto abierto no abre otro
ni pisa lo que ya contó: solo llena lo vacío y lo nuevo (nombre, respuestas, interés)
queda completo en el evento «Reenvío» de su historial.

**Envío como máximo una vez.** Antes de entregarlo, el mensaje se toma («enviando», con
su intento). Si la pasada se corta antes de anotar cómo terminó, no se vuelve a mandar: a
los 15 minutos queda fallido, sin reintento y con el motivo, para revisarlo. Un aviso
repetido es peor que uno por revisar. «Enviando» es interno: la bandeja de salida lo
muestra y lo cuenta como «en cola».

**Alcance por sede (R19, ADR 0098).**

- Reportes de Wellhub, TotalPass y Fitpass: quien está acotado ve las visitas y reservas
  de las clases de sus sedes y a los visitantes que vinieron o reservaron en ellas. Lo
  mismo los horarios de Fitpass y lo que falta marcar en su panel.
- La conciliación con las plataformas y la bitácora son de todo el negocio: un rol
  acotado no las ve (403).
- Accesos: un pase vale en cualquier sede (la persona puede ser de otra), pero quien está
  acotado solo registra entradas en una de sus sedes (sin indicarla, la suya si tiene una).

**Contracargo perdido sobre un pago ya devuelto.** No se registra otra devolución (nunca
más de lo que quedaba): la incidencia del contracargo dice que el cliente recibió su
dinero dos veces.

**Orden de bloqueos.** Orden → pago → reserva → sesión: la expiración de un apartado
bloquea primero sus órdenes y después la reserva, como la aprobación del pago.

## Consecuencias

- La web muestra la incidencia `devolucion_externa` con el título de devolución (trae su
  devolución); su texto dice qué decidir.
- Un rol acotado con permiso de auditoría o de facturación ya no ve la bitácora ni la
  conciliación con las plataformas.
- Sin migraciones: `estado` de los mensajes y `tipo` de las incidencias son texto.
