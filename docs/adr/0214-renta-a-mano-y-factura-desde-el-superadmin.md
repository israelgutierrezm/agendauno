# ADR 0214 — La renta a mano y su factura desde el superadmin

Estado: Aceptado (2026-10-26). Completa el ADR 0107 (cobro de la renta) y el 0192
(Mercado Pago y OpenPay para la renta).

## Contexto

La revisión para la V1 encontró que el superadmin solo podía condonar un cargo. Un
negocio que pagaba por transferencia o en efectivo quedaba debiendo (y se suspendía),
no había forma de cobrarle algo aparte (una migración, una configuración), su factura
salía siempre con «tarjeta de crédito» como forma de pago aunque hubiera pagado de otra
manera, y si la factura fallaba o había que cancelarla (una renta devuelta) solo se
podía arreglar fuera del sistema.

## Decisión

- **Registrar un pago recibido fuera de la pasarela** (`CobrosManualesRenta`):
  transferencia, depósito, efectivo o cheque, con su referencia y su fecha. El cargo
  queda pagado (bloqueado en la transacción: una sola vez) y sigue lo de cualquier pago
  (`ConfirmarCargoRenta::despuesDelPago`): reactivar al negocio suspendido que ya no
  debe, sumar timbres o mensajes comprados y el mes gratis de un invitado. Su
  referencia lleva el prefijo `manual:` y nunca coincide con la de una pasarela.
- **Cargo manual** (concepto `manual`): descripción, importe IVA incluido (el general en
  México, sin IVA fuera), moneda y vencimiento. El negocio lo ve en su suscripción y lo
  paga como su renta; su recibo y su factura llevan la descripción.
- **Forma de pago del SAT real**: el CFDI de la renta sale con la del pago registrado a
  mano (03 transferencia, 01 depósito o efectivo, 02 cheque); con una pasarela, la
  configurada. Se guarda en la factura.
- **La factura desde la ficha** (`FacturasRentaPlataforma`): emitirla o reintentarla con
  los datos fiscales que el negocio guardó en su base, ver por qué falló, descargar su
  PDF o XML y cancelarla ante el SAT con el motivo `02` o `03`, siguiendo cómo va la
  cancelación (en espera, aceptada, rechazada o vencida).
- Todo pasa por las rutas del superadmin: queda en su bitácora con su operador.

## Consecuencias

- Un negocio que paga por transferencia no se suspende por error y su factura dice
  cómo pagó.
- La devolución del dinero de una renta se sigue haciendo en el panel de la pasarela;
  aquí se cancela su factura.
