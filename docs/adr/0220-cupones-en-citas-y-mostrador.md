# ADR 0220 — Cupones al agendar, al cobrar una cita y en el mostrador

Estado: Aceptado (2026-10-26). Extiende los cupones de las órdenes (`promociones`).

## Contexto

Los cupones (`promociones`) solo se aplicaban al comprar en línea un bono o una
membresía. En un negocio de citas el descuento se da al agendar (la página pública o la
cuenta del cliente), al cobrar la cita en el negocio o al vender en el mostrador. El
dueño lo pidió para la V1.

## Decisión

- **Una sola regla de validez**: `GestionarPromocionesTenant` (vigencia, usos, mínimo,
  porcentaje o monto fijo). El descuento nunca pasa del total de lo que descuenta.
- **Al agendar** (`POST /citas`, `POST /mi/citas`, `POST /agenda/citas`): campo opcional
  `cupon`. Se aplica a la orden pendiente de la cita (o a la del saldo, si hubo
  anticipo) con `OrdenesTenant::aplicarCupon`, dentro de la misma transacción: un
  cupón que no sirve no deja agendar (422). Solo en lo que se paga: no con bono, ni en
  un servicio pagado por adelantado. Una orden con cupón no acepta otro.
- **Revisar antes**: `GET /citas/cupon?codigo&oferta_id` (público, 20 por minuto)
  dice cuánto descuenta sobre el precio del servicio; no lo usa.
- **Al cobrar la cita** (`POST /reservas/{reserva}/cobro`): `cupon` descuenta del
  servicio pendiente; ya cobrada, no se aplica otra vez. Se revisa con
  `POST /promociones/validar` (el mismo permiso del cobro).
- **En el mostrador** (`POST /pos/ventas`): `cupon` se reparte entre las líneas (una
  devolución regresa lo que de verdad se pagó); la venta guarda `descuento_minor` y
  `promocion_id`. Se revisa con `POST /pos/cupon`, con el permiso de vender.
- **El uso se cuenta al pagarse**: las órdenes al cumplirse (como siempre), la venta
  del mostrador al venderse. Un reintento con la misma clave no lo cuenta dos veces.
- En la web, un solo campo «¿Tienes un cupón?» (`CampoCupon`) en los cuatro lugares;
  si cambia sobre cuánto se calcula (el carrito), se revisa otra vez. El servidor
  siempre vuelve a validar.

## Consecuencias

- Migración del negocio `000201`: `ventas_pos.descuento_minor` y `promocion_id`.
- La app móvil ofrece el mismo campo al agendar desde la cuenta del cliente
  (`GET /mi/citas/cupon` y `cupon` en `POST /mi/citas`); la app del equipo (agendar o
  cobrar una cita) todavía no.
