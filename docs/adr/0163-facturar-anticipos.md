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
  órdenes de una cita.
