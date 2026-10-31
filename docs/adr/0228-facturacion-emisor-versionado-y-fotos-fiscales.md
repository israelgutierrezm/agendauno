# ADR 0228 — Emisores versionados, RFC del sello y foto fiscal de lo vendido

Estado: Aceptado (2026-10-30). Completa los ADR 0163, 0179, 0180 y 0212.

## Contexto

La revisión de la facturación (CFDI con FacturAPI) para la V1 encontró cuatro problemas:

- **Cambiar el RFC dejaba inaccesibles las facturas de antes.** Se olvidaban la
  organización y la llave. El PDF, el XML, reenviar y cancelar usaban la llave de hoy, y
  FacturAPI solo conoce cada factura en la organización que la timbró.
- **Un sello (CSD) de otro RFC podía dejar la facturación lista.** Nadie comparaba el RFC
  del certificado con el capturado.
- **El IVA era inconsistente.**
  - Con un descuento o una devolución, una venta mixta iba en un solo concepto con el IVA
    general.
  - La factura leía el IVA del catálogo de hoy, no el del día de la venta.
  - Además, la pantalla de Facturas no mandaba el IVA de cada concepto: todo salía con el
    general, aunque el borrador dijera exento.
- **«Mis facturas» no tenía estados.** Si fallaba, desaparecía. Traía 100 como máximo, sin
  páginas ni periodo.

Lo que dice la documentación de FacturAPI (docs.facturapi.io, API y Quickstart; consultada
el 2026-10-30) y decide el diseño:

- El RFC de una organización es `legal.tax_id` y se toma del certificado. Subir un
  certificado **reemplaza** al anterior y reasigna ese RFC.
- Crear una llave en vivo no invalida las anteriores (hasta 5 por hora y 10 por día), y
  FacturAPI **borra las llaves que pasan más de un año sin usarse**.
- Quitar el certificado no afecta lo ya emitido. Borrar la organización sí: se pierde el
  acceso a sus facturas.

## Decisión

### B. Se versiona al emisor; no se bloquea el cambio de RFC

**Por qué versionar.** Cada RFC tiene su propia organización de FacturAPI (ADR 0212) y esa
organización no se borra. Conserva sus facturas y su sello, así que descargar, reenviar y
cancelar siguen funcionando con ella. Bloquear el cambio solo cambiaba el problema de
lugar: un negocio que pasa de persona física a moral se quedaría sin poder facturar, o con
un procedimiento a mano para cada caso.

**Cómo se guarda** (`emisores_fiscales`, `EmisoresFiscalesTenant`):

- Cada emisor es un RFC, su razón social, su organización y su llave (cifrada, nunca se
  devuelve). `reemplazado_en` dice desde cuándo ya no se timbra con él.
- Cada factura guarda `emisor_fiscal_id` y `emisor_rfc`.
- Al guardar otro RFC, el emisor de hoy queda reemplazado con su llave, y el alta empieza
  de nuevo con otra organización. Esto ya ocurría; lo nuevo es lo que se conserva.

**Una factura vieja usa a su emisor.** El PDF y el XML (del equipo y de «Mis facturas»),
reenviarla, cancelarla y conciliar su cancelación usan la llave del emisor que la timbró.

**La llave se recupera.** Si FacturAPI responde 401 (`LlaveCfdiRechazada`), se crea otra con
la llave de usuario de la plataforma, se guarda y se intenta una vez más. Vale para el
emisor reemplazado y para el de hoy al timbrar, porque un negocio que pasa un año sin
facturar perdería la suya igual.

**Lo que no se hace con otro RFC.** Un CFDI nuevo relacionado con una factura que timbró
otro RFC no se emite: ni nota de crédito, ni sustituta, ni aplicar su anticipo (422). Otro
RFC es otro contribuyente. La factura se marca (`de_otro_rfc`), no ofrece nota de crédito y
sus anticipos no se listan. En automático (ADR 0163), el resto de esa cita se factura como
cualquier venta y el reintento no insiste con ese anticipo. Se puede cancelar y volver a
facturar.

**Lo que ya hay.** La migración crea el emisor de hoy, con la llave copiada tal cual, y le
liga las facturas timbradas. Al retirar un emisor se le ligan también las que no digan el
suyo.

### C. El RFC del sello se compara, antes y después de subirlo

- **Antes de subirlo** (`RfcDeCertificado`): se lee del propio `.cer` (el primer dato de
  `x500UniqueIdentifier`). Si es de otro RFC, 422 en `sello` y no se toca FacturAPI, porque
  subirlo reemplazaría el sello bueno. Si no se puede leer, decide FacturAPI.
- **Después de subirlo y al «Revisar de nuevo»**: se compara con `legal.tax_id`. Si es otro:
  - no se crea la llave y la facturación no queda lista;
  - queda `rfc_del_sello`, con lo que `sello_de_otro_rfc` es verdadero y `sellos_cargados`
    es falso;
  - timbrar responde 422 en `facturacion` con el motivo.
- **Para corregirlo**, se sube el sello correcto: reemplaza al otro en la misma organización.
  O se corrige el RFC capturado, que es el cambio de RFC de arriba.

### D. Cada línea guarda su foto fiscal al venderse

- **La foto** (`FotoFiscal`): el IVA (`general`, `exento` o `cero`) y las claves del SAT de
  producto o servicio y de unidad (`iva`, `clave_prod_serv`, `clave_unidad`).
  - Se guarda al crear cada línea de orden y de mostrador, en el propio modelo, para que
    ninguna forma de vender se la salte.
  - Una orden de cita no tiene líneas: la guarda la orden, con los datos de su servicio.
- **El borrador usa la foto**, con un concepto por línea. Así exentos y gravados nunca van
  juntos, y que el catálogo cambie después no altera lo vendido.
- **Descuento y devoluciones, explícitos.** Cada línea se factura por lo que se cobró de ella:
  - La promoción se reparte entre lo vendido, porque no toca las cuotas (B04).
  - Lo demás (descuento de contado, lo devuelto, los impuestos desglosados de un
    presupuesto) se reparte entre todas las líneas, en proporción y en centavos exactos.
  - El borrador trae `descuento_minor` y `devuelto_minor`, y la pantalla los dice.
  - En el mostrador el cupón ya va en cada línea (ADR 0220). De cada línea se factura lo
    cobrado menos lo que se le devolvió.
  - Si el importe no se divide exacto entre las piezas, va una partida «3 × Producto» para
    no perder centavos.
- **El API no se fía de la pantalla.** Una factura ligada a una venta lleva el IVA de su
  foto; si no, 422 en `items`:
  - Si toda la venta lleva el mismo IVA, el concepto que no lo diga lo toma.
  - Si mezcla, cada concepto dice el suyo y lo de cada IVA debe ser lo de la venta, a unos
    centavos.
- **Ventas de antes, sin foto.** Se factura con el catálogo de hoy como respaldo y queda
  anotado: el borrador trae `fiscal_del_catalogo` y la pantalla pide revisarlo. Ahí vale lo
  que se revisó en pantalla, porque el catálogo pudo cambiar desde la venta.
- **La nota de crédito de una factura mixta** lleva un concepto por cada IVA, con el monto en
  proporción a lo que la factura llevó de cada uno. Antes iba toda con el general.
- **La pantalla** manda el IVA de cada concepto y deja elegirlo, también en un anticipo.
  Calcula el total con el de cada uno.

### E. «Mis facturas» por páginas y por periodo

- `GET /mi/facturas` acepta `page`, `per_page`, `desde` y `hasta` (días del negocio en que se
  timbró). Trae `meta` con `total`, `page`, `per_page` y `ultima_pagina`, como los demás
  listados, y `disponible` (si el negocio factura). `data` no cambia. La app Flutter no
  consume este endpoint.
- Quien no tiene ficha en el negocio recibe la lista vacía en vez de un 403, como en sus
  compras del mostrador (ADR 0145).
- La pantalla dice que está cargando, si falló (con «Reintentar», que vuelve a la misma
  página) y si no hay facturas. Si el negocio no factura y no hay ninguna, no aparece.

## Consecuencias

- **Migración de negocio 000204.** Hay que correr `agendauno:migrar-estudios`: el código ya
  escribe en esas columnas al crear órdenes y líneas.
- **Sin validar con FacturAPI de verdad:** todo se probó con el proveedor simulado, sobre la
  documentación. Falta confirmar con un RFC real que `legal.tax_id` llega en la respuesta al
  subir el sello y que una llave borrada responde 401. Si `legal.tax_id` no llegara, valdría
  solo la lectura del archivo.
- **Un emisor reemplazado sigue activo en FacturAPI.** Si su sello vence, cancelar puede
  fallar y se dice el motivo que dé FacturAPI; descargar y reenviar siguen. No hay pantalla
  para renovarle el sello: si hiciera falta, lo hace soporte con la llave de usuario.
- **Un cambio de RFC anterior a esta versión** perdió el id de su organización: esas facturas
  quedan ligadas al emisor de hoy y FacturAPI no las encontrará. Para recuperarlas, soporte
  busca la organización con la llave de usuario (`GET /organizations`), le crea una llave y
  registra el emisor y sus facturas en la base del negocio. Solo afecta a un negocio que ya
  haya timbrado de verdad.
- **Organizaciones sin facturas.** Un RFC mal capturado antes de timbrar deja su organización
  vacía en la cuenta de FacturAPI. No se borran: borrar es irreversible y no estorban.
- **La tasa del IVA general no se fotografía.** La foto guarda si el concepto es general,
  exento o tasa cero. El porcentaje sigue siendo el parámetro del negocio
  (`facturacion.iva_porcentaje`, 16 u 8): depende de dónde está el negocio, no de lo vendido.
  Si un negocio lo cambia, sus ventas sin facturar salen con el nuevo.
- **Las claves del SAT de un concepto siguen siendo editables** en pantalla, para corregir una
  clave mal puesta. El IVA es lo único que el API exige igual a la foto.
- **Actualiza** los ADR 0179 (ajuste de 2026-10-21) y 0212 (consecuencias): una venta mixta ya
  no va en un concepto con el IVA general.
