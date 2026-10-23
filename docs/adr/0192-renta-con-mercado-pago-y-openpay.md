# ADR 0192 — Renta con Mercado Pago y OpenPay

Estado: Aceptado (2026-10-20). Sin validar con llaves reales.

## Contexto

La renta del SaaS se cobraba solo con Stripe. El comparativo pedía dejar Mercado Pago y
OpenPay como «próximamente» en la versión 1, y el dueño pidió implementarlo ya. Los
negocios ya cobran a sus clientes con las dos (ADR 0047 y siguientes), así que sus
clientes de API existen.

## Decisión

- **Las tres pasarelas de la plataforma cobran la renta**, cada una con sus llaves del
  superadmin. Sin ellas no se activa:
  - Stripe: `secret_key`;
  - Mercado Pago: `access_token`;
  - OpenPay: `merchant_id` y `private_key`.

  `GET /renta` dice cuáles están activas (`pasarelas`), en orden: Stripe, Mercado Pago,
  OpenPay. Con más de una, el dueño elige con cuál paga. Sin elegir, se usa la primera.
- **Mercado Pago** (Checkout Pro):
  - una preferencia con el cargo como `external_reference`: tarjeta, OXXO o
    transferencia, con 3 días para pagar en tienda;
  - el aviso llega a `/webhooks/plataforma/mercadopago`, firmado con el `webhook_secret`
    de la plataforma;
  - el cobro se relee en la API y solo un `approved` del mismo cargo, por el mismo
    importe, lo da por pagado;
  - antes de abrir otra preferencia se cancela lo que quede sin cobrar y se vence la
    anterior. Si ya se cobró o se está cobrando, se espera.
- **OpenPay**:
  - un cargo con tarjeta en su página (3D Secure si el banco lo pide);
  - como OpenPay no admite dos cargos con el mismo `order_id`, cada intento lleva
    `{cargo}-{fecha y hora}`;
  - el aviso llega a `/webhooks/plataforma/openpay` con el usuario y la contraseña que
    guardó la plataforma (HTTP Basic);
  - el cargo se relee en la API: `completed` lo paga; `failed` o `cancelled` cierra el
    intento.
- **Conciliación**: `agendauno:conciliar-renta` también pregunta a Mercado Pago (por el
  cargo) y a OpenPay (por su cargo) por los pendientes de esas pasarelas. Lo mismo hace
  el reintento de «Pagar» antes de abrir otro intento.
- **La domiciliación sigue solo con Stripe**: guardar la tarjeta y cobrar cada mes
  (ADR 0107).

## Consecuencias

- Las alertas de devolución o disputa de un cobro de la renta siguen solo con Stripe.
  En Mercado Pago y OpenPay se ven en su panel.
- Sin validar con llaves reales: probado con respuestas simuladas de las dos API.

## Condonar con un pago en curso (2026-10-22)

- **Mercado Pago**: al condonar se cancelan sus cobros pendientes (un ticket de OXXO) y se
  vence la preferencia; con un cobro ya aprobado, no se condona (422) y la conciliación
  lo da por pagado. Si aun así llega un pago aprobado, el cargo sigue condonado y se
  avisa del cobro doble (`renta_cobrada_dos_veces`) para devolverlo.
- **OpenPay no deja cerrar su página de pago**: el intento que seguía abierto se guarda
  (`cargos_renta.referencia_condonada`, migración de la plataforma 000410) y la
  conciliación lo vigila 30 días. Si se paga, avisa del cobro doble aunque el aviso de
  OpenPay se pierda; si se cancela o falla, deja de vigilarlo.
