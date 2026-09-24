# ADR 0021 — Mercado Pago y OpenPay por negocio (cobro, avisos, devoluciones y pago automático)

Estado: Aceptado (2026-09-24). Reemplaza la integración del esquema compartido
(ADR 0016, código borrado con el legacy). Hechos de las APIs verificados en la
documentación oficial (Mercado Pago y OpenPay México, 2026-09).

## Contexto

Tras el rediseño por negocio solo Stripe cobraba en línea; Mercado Pago y OpenPay
se mostraban como "Próximamente". Se pidió cobro automático mensual en todas las
pasarelas, lo que exige primero su integración completa.

## Decisiones

### Comunes
- Cliente HTTP propio por pasarela, sin SDK, con las llaves del negocio; montos en
  pesos con decimales solo al armar la petición (`MontoDecimal`), centavos enteros en
  todo lo demás.
- **El aviso (webhook) nunca es la fuente de verdad**: solo dice qué cambió; el
  cobro se relee en la API con la llave privada del negocio antes de confirmar.
  Avisos repetidos no tienen efecto.
- URL de avisos por negocio: `/api/v1/webhooks/tenant/{slug}/{proveedor}`; la
  pantalla Pasarelas la muestra con las instrucciones de cada tablero.
- Un intento cerrado que aun así se paga se confirma si la compra sigue pendiente
  (`ConfirmarPagoTenant::aprobar`) y se cierran los demás intentos abiertos.
- La plataforma (renta del SaaS) sigue cobrando solo con Stripe
  (`ProveedorPasarela::implementadasPlataforma()`).

### Mercado Pago
- Checkout Pro: preferencia con `external_reference` = ulid del pago,
  `notification_url` del negocio, `back_urls` https y vencimiento de 3 días (también
  el ticket en efectivo). La referencia del pago es la preferencia hasta que existe un
  cobro (p. ej. ticket de OXXO), y entonces el id de ese cobro.
- Aviso: firma `x-signature` (HMAC-SHA256 de `id:{data.id};request-id:{x-request-id};ts:{ts};`
  con la clave secreta; `data.id` de la URL, en minúsculas; en PHP llega como
  `data_id`). Sin clave en producción no se acepta. Un rechazo de tarjeta no cierra el
  intento (el cliente puede probar otra en la misma página).
- Reintento: se cancelan los cobros sin pagar de la referencia y se vence la
  preferencia; si ya hay un cobro aprobado o en proceso, no se abre otro intento.
- Devoluciones con `X-Idempotency-Key` (lo de efectivo vuelve al saldo de Mercado
  Pago del cliente).
- **Pago automático = suscripción sin plan (`preapproval`)** en estado pendiente: el
  cliente la autoriza en Mercado Pago (`init_point`); `payer_email` es el del alumno;
  inicio en la próxima renovación (con fin a 5 años, porque sin fin Mercado Pago
  ignora el inicio). Mercado Pago cobra solo y reintenta.

### OpenPay
- Tarjeta con la página de OpenPay (`confirm:false` + `redirect_url`); OXXO/tiendas
  con referencia, código de barras y recibo PDF alojado por OpenPay (3 días).
  `order_id` = ulid del pago (OpenPay no admite dos cargos con el mismo) y la IP del
  cliente en `X-Forwarded-For`.
- Aviso protegido con HTTP Basic (usuario y contraseña que el negocio guarda);
  el código de verificación que manda OpenPay al registrar el webhook se guarda y se
  muestra en Pasarelas para capturarlo en su tablero.
- OpenPay no cancela cargos pendientes: al reintentar solo se niega si ya se cobró.
  Solo devuelve pagos con tarjeta; lo de tienda se registra como devolución manual.
- **Pago automático = su API de suscripciones** (la única vía documentada; cobrar
  una tarjeta guardada exige `device_session_id` de un navegador). La tarjeta se
  captura con OpenPay.js en la web (token de un solo uso + sesión del dispositivo;
  el número nunca pasa por AgendaUno), se guarda en un cliente de OpenPay propio de
  la membresía, con un plan mensual por su precio y el primer cobro en la próxima
  renovación. En la app se remite a la web para capturarla.

### Suscripciones (Mercado Pago y OpenPay)
- Contrato `PasarelaConSuscripcion` (`suscribir`, `consultarSuscripcion`,
  `cobrosDeSuscripcion`, `cancelarSuscripcion`), distinto de `PasarelaDomiciliable`
  (Stripe, donde el sistema cobra).
- `ConciliarSuscripcionTenant` asienta cada cobro de la pasarela una sola vez (su id)
  sobre la deuda del periodo (`DeudaDeRenovacionTenant`): aprobado → fulfillment
  (la fecha avanza, la mora se regulariza); rechazado → mora con el motivo. Lo
  disparan los avisos y la corrida diaria de renovaciones, que para estas
  membresías **no cobra por su cuenta**.
- Pausar una membresía con suscripción la cancela en la pasarela (Mercado Pago y
  OpenPay seguirían cobrando); al reanudar, el alumno la vuelve a activar.

## Consecuencias

- Probar en vivo requiere cuentas de prueba de cada pasarela: Mercado Pago no manda
  avisos de pagos hechos con credenciales de prueba (usar "Simular" en su panel) y el
  vendedor/comprador de prueba debe ser del mismo país; OpenPay manda avisos reales
  en sandbox y su webhook debe verificarse a mano con el código.
- Cambiar el precio de una membresía no cambia lo que ya cobra una suscripción de
  Mercado Pago u OpenPay (en OpenPay el plan no se puede modificar): el alumno debe
  quitar y volver a activar el pago automático. Se registra lo que la pasarela cobró.
- Pendiente de confirmar en sandbox: cuándo cobra Mercado Pago la primera cuota con
  inicio futuro y la forma exacta de sus avisos de suscripción (por eso también se
  concilia en la corrida diaria).
