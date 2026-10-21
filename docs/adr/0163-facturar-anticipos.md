# ADR 0163 — Facturar anticipos: CFDI del anticipo y CFDI por remanente

Estado: Aceptado (2026-10-18). Cierra el pendiente fiscal del ADR 0161 (anticipo de
citas) y vale para cualquier anticipo que el negocio facture.

## Contexto

Con el anticipo de citas (ADR 0161) el cliente paga una parte al apartar y el resto al
terminar. En México, si el cliente pide factura del anticipo, el SAT pide un
procedimiento propio (Anexo 20, apéndice de anticipos recibidos): el CFDI del
anticipo lleva un concepto fijo y, al facturar la operación, hay que relacionarlo.
Las facturas del panel se emiten a mano con conceptos libres (no se generan de una
orden), así que no había forma de hacerlo bien.

El SAT da dos caminos: aplicar el anticipo con un CFDI de egreso (tres comprobantes)
o facturar solo el remanente (dos comprobantes). Elegimos el **remanente**: es el
más simple para un negocio pequeño y gasta un timbre menos.

## Decisión

- **Factura de un anticipo** (`POST /facturas` con `anticipo: true`): un solo
  concepto. El API fija la clave `84111506`, la unidad `ACT`, la cantidad 1 y la
  descripción «Anticipo del bien o servicio»; de lo que se manda solo toma el
  importe. Queda marcada (`facturas.anticipo`).
- **Factura del resto** (`anticipos: [factura…]`): se relaciona con esos anticipos
  con el tipo de relación `07` (`related_documents` en FacturAPI) y cada concepto
  lleva la leyenda «CFDI por remanente de un anticipo». El importe que se factura es
  solo el resto: el negocio no vuelve a facturar lo que ya facturó como anticipo.
- **Un anticipo se aplica una sola vez**: debe ser del mismo RFC, estar timbrado y no
  estar aplicado. Se bloquea y se revalida en la misma transacción del timbrado;
  timbrada la factura del resto, queda con `anticipo_aplicado_en`. Si algo no cuadra
  responde 422 en `anticipos` y no gasta timbre.
- `GET /facturas?anticipos_de=<RFC>` lista los anticipos timbrados y sin aplicar de
  ese RFC. La web los ofrece al capturar el RFC.
- Fuera de alcance: el camino con CFDI de egreso y facturar en automático desde las
  órdenes de una cita. Resuelto después: ver «Facturas automáticas de citas».

## Facturas automáticas de citas (2026-10-21)

Pedido del dueño: facturar los anticipos y las citas sin capturarlos a mano, con su CFDI
de egreso al aplicar el anticipo.

- **Datos para facturar del cliente** (`datos_fiscales_personas`, migración de negocio
  000195): los guarda el cliente en su cuenta (web y app) o el negocio en su ficha. Al
  facturar a mano, el borrador los toma.
- **El negocio la enciende** (`facturacion.automatica_citas`, apagada por omisión) y el
  cliente la pide (`factura_automatica`). Solo negocios de citas que facturan con la
  plataforma (México, con su función de facturación y sus datos fiscales).
- **Al pagarse la orden de una cita** (evento `orden.pagada`):
  - **el anticipo**: su CFDI de anticipo (como arriba), ligado a la orden del anticipo;
    así no se factura dos veces;
  - **el resto**: aquí sí el camino de tres comprobantes del Anexo 20. La factura de la
    operación completa (el servicio por su precio, sin la leyenda del remanente),
    relacionada `07` con el anticipo y con la forma de pago del resto; y el **CFDI de
    egreso** que aplica el anticipo: «Aplicación de anticipo» (`84111506`/`ACT`) por el
    importe del anticipo, forma de pago `30`, uso `G02`, relacionado `07` con la factura
    de la operación. Timbrado el egreso, el anticipo queda aplicado;
  - **una cita pagada completa**: su factura con los conceptos del borrador.
  - El remanente (dos comprobantes) sigue siendo el camino al facturar a mano.
- **Reintentos**: `agendauno:facturar-citas` (cada hora) factura lo que no se timbró
  (hasta tres errores por orden, de los últimos siete días) y aplica los anticipos cuyo
  egreso falló.
- El egreso cuenta como lo acreditado de la factura de la operación: una nota de crédito
  posterior solo puede acreditar lo demás.
- Sin validar con el SAT ni con FacturAPI reales: probado con el proveedor simulado.
