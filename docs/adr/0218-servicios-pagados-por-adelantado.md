# ADR 0218 — Servicios pagados por adelantado (presupuesto pagado → cita)

Estado: Aceptado (2026-10-26). Completa el ADR 0127 (presupuestos).

## Contexto

Un presupuesto con un servicio del catálogo («2 sesiones de facial») se convertía en
una orden con un concepto escrito, sin ligarse al servicio. El cliente lo pagaba y,
al agendar cada sesión, la cita de ese servicio (que se cobra al agendar) le pedía
pagar otra vez: cobro doble. La revisión para la V1 lo marcó como bloqueo.

## Decisión

- Al convertir un presupuesto, el concepto de un servicio guarda cuál es
  (`lineas_orden.oferta_id`).
- Al agendar una cita de un servicio que se cobra, si la persona tiene ese servicio en
  una orden pagada y le quedan veces (`ServiciosPrepagadosTenant`), la cita queda
  confirmada con ese concepto (`reservas.linea_orden_id`), sin orden nueva. Pasa igual
  si agenda el negocio o el cliente (en su cuenta o en la página, con su correo).
- Cada concepto se usa hasta su cantidad; una cita cancelada libera su vez. Usadas
  todas, la siguiente se cobra como siempre. Una orden devuelta (cancelada) ya no
  cuenta.
- El concepto se toma bajo candado dentro de la transacción que agenda: dos citas a la
  vez no usan la misma vez. Una cita así no cuenta para el tope de citas por pagar.
- La cuenta del cliente dice «Ya lo pagaste» y cuántas veces le quedan, y no pide
  anticipo.

## Consecuencias

- Cancelar una cita prepagada no devuelve dinero: lo pagado sigue en el presupuesto y
  la vez queda libre para agendar otra. Devolver el presupuesto se hace desde su orden.
