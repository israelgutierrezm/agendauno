# ADR 0020 — Pago automático (domiciliación) de membresías

Estado: Aceptado (2026-09-24). Stripe implementado; OpenPay y Mercado Pago siguen
el mismo contrato cuando tengan su integración tenant-local.

## Contexto

La renovación mensual de una membresía abría una orden de renovación y, si el
negocio cobraba en línea, mandaba al alumno un enlace para pagarla (Checkout).
Cada mes el alumno tenía que pagar a mano; si no lo hacía entraba en mora. El dueño
del producto pidió cobro automático mensual (domiciliación) en las opciones de pago
de membresías.

## Decisiones

- **El alumno autoriza su tarjeta en la pasarela, nunca en AgendaUno**: Stripe
  Checkout en modo `setup` (desde "Pago automático" en su cuenta) o al pagar una
  compra con `domiciliar` (`setup_future_usage=off_session`). Solo se guardan
  referencias (cliente `cus_…`, método `pm_…`) y lo necesario para mostrarla
  (marca, últimos 4, vencimiento).
- **Por membresía**: `domiciliaciones` (tenant) liga un acuerdo con el método de
  pago (`activa`/`cancelada`). Una persona tiene una tarjeta por pasarela: si
  autoriza otra, todo lo domiciliado pasa a cobrarse ahí y la anterior se desliga.
  `clientes_pasarela` guarda el cliente de la persona en la cuenta de la pasarela.
- **La persona se identifica por su cliente de la pasarela**, no por la metadata
  del webhook, y solo se domicilian membresías suyas que se renuevan.
- **El motor de renovación no cambia**: la deuda del periodo sigue siendo la orden
  de renovación y la fecha solo avanza al pagarla (ADR de renovaciones). Si la
  membresía está domiciliada, `CobroRecurrenteTenant` la cobra sin el cliente
  presente (`off_session`) en vez de mandar el enlace.
- **Resultados del cargo automático**:
  - aprobado → fulfillment (fecha avanza, mora se regulariza, error se limpia);
  - rechazo del banco (incluye `authentication_required`) → mora con el motivo
    ("No pudimos cobrar tu pago automático a la tarjeta Visa terminación 4242: …")
    y el enlace para pagar a mano; los reintentos vuelven a intentar la tarjeta;
  - la pasarela no respondió → sin mora (no es culpa del cliente); se reintenta en
    la siguiente corrida;
  - en proceso → se espera el webhook (`payment_intent.succeeded/payment_failed`).
- **Idempotencia**: la llave del cargo es `domiciliacion_{orden}_{intento}`, con el
  intento contado sobre pagos ya registrados. Si la pasarela no respondió, la
  transacción se deshace y el reintento usa la misma llave: no se cobra dos veces.
- **Contrato por pasarela** (`PasarelaDomiciliable`): `iniciarGuardado`,
  `cobrarDomiciliado`, `olvidarTarjeta`. Una pasarela sin este contrato no ofrece
  pago automático (`DomiciliacionesTenant::proveedor()` es null).
- **Negocio**: ve en Cobranza qué renovaciones se cobran solas y el último rechazo;
  puede invitar al alumno por correo (`pago_automatico.solicitado`, plantilla
  editable) o quitarlo a petición suya. No captura tarjetas.
- **Se quita solo** al cancelar la membresía por reembolso total o por baja de
  datos (ARCO), que además olvida el cliente en la pasarela.

## Consecuencias

- La prueba en vivo requiere llaves sandbox de Stripe del negocio y su webhook
  (`checkout.session.completed`, `payment_intent.succeeded`,
  `payment_intent.payment_failed`).
- Cambiar de cuenta de Stripe invalida las tarjetas guardadas (son de la cuenta):
  los cargos se rechazan con "La tarjeta guardada ya no está disponible" y el
  alumno debe autorizar de nuevo.
- Mercado Pago cobra sus suscripciones (`preapproval`) con su propio calendario; al
  integrarlo, su contrato marcará que la pasarela cobra sola y el webhook de cada
  cargo pagará la orden de renovación.
