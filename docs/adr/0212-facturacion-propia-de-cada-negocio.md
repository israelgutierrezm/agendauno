# ADR 0212 — Cada negocio factura con su propia organización de FacturAPI

Estado: Aceptado (2026-10-25). Completa el ADR 0163 (facturar ventas y anticipos) y el
0179 (factura de una venta).

## Contexto

La revisión para la V1 encontró que la factura de un negocio a sus clientes estaba a
medias: nada daba de alta al negocio en FacturAPI ni subía su sello (CSD), así que se
timbraba con la llave de la plataforma, es decir, con otro emisor. El PDF del CFDI se
enlazaba directo a FacturAPI, que lo niega sin llave, y el XML no se ofrecía; nada lo
mandaba por correo. Además todo concepto llevaba el IVA general, aunque los servicios
médicos suelen ser exentos.

## Decisión

- **Dos llaves de la plataforma.** La de usuario (`sk_user_…`, `FACTURAPI_LLAVE_USUARIO`
  o el superadmin) da de alta a los negocios; la de la organización de la plataforma
  (`sk_live_…`, `FACTURAPI_LLAVE`) factura la renta.
- **Alta del negocio** (`AltaFacturacionNegocioTenant`, contrato `OrganizacionesCfdi`):
  al subir su sello se crea su organización, se le suben sus datos fiscales y su CSD y
  se crea su llave en vivo, que se guarda cifrada en su base. Un sello que FacturAPI
  rechaza no se guarda y se dice por qué. Cambiar de RFC empieza el alta de nuevo.
- **Lo que falta para timbrar** (`pending_steps`, `is_production_ready`) se guarda y se
  muestra: la carta manifiesto la firma el negocio con su e.firma en el portal público
  de FacturAPI; «Revisar de nuevo» vuelve a preguntar.
- **Se timbra solo con la llave del negocio** (`EmitirFacturaTenant::llaveDe`): sin
  ella, en producción o con FacturAPI de verdad, se pide terminar el alta. Nunca con la
  de la plataforma.
- **PDF y XML** con la llave del negocio: `GET /facturas/{id}/{pdf|xml}` para el equipo
  y «Mis facturas» (`/mi/facturas`) para el cliente. Al timbrarse, el CFDI va por correo
  al receptor (outbox → cola `EnviarFacturaPorCorreo`); el equipo puede reenviarlo.
- **IVA por lo vendido** (`TratamientoIva`: general, exento o tasa cero) y clave del SAT
  opcional en cada servicio, plan y artículo; las notas de crédito y la aplicación de
  un anticipo llevan el de su factura.

## Consecuencias

- Falta del dueño: la cuenta de FacturAPI, las dos llaves y validar el alta y el
  timbrado con un RFC real (el proveedor va simulado en las pruebas).
- Si un negocio vende algo exento y algo con IVA en una venta que no se factura por
  líneas, el concepto único lleva el IVA general.
