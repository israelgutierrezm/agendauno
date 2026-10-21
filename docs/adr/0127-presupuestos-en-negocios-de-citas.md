# ADR 0127 — Presupuestos en los negocios de citas

Estado: Aceptado (2026-10-11). Pedido del dueño: TurnoUno no tenía presupuestos.

## Contexto

Clínicas, estéticas, spas y barberías cotizan antes de atender: un tratamiento de varias
sesiones, un paquete para novias, un servicio con producto. Hasta ahora no había forma
de hacerlo en la plataforma.

El dueño decidió cuatro cosas:
- Los impuestos los configura cada negocio: incluidos en el precio o desglosados, con
  su tasa.
- El negocio marca si el cliente aceptó o rechazó. No hay aceptación en línea.
- Aceptado, el presupuesto se convierte en cobro.
- Los conceptos pueden ser servicios del catálogo, bonos (sin membresías desde el ADR 0130),
  artículos del inventario y conceptos libres.

## Decisión

**Datos** (base de cada negocio):
- `presupuestos`:
  - folio consecutivo del negocio (P-0001);
  - cliente (persona) o contacto que aún no lo es (nombre, correo, celular);
  - sucursal y vigencia;
  - impuestos congelados al crearlo: modo y tasa en puntos base;
  - totales en centavos;
  - estado, notas y la orden en que se convirtió.
- `lineas_presupuesto`: tipo (servicio, producto, artículo o libre), su referencia,
  descripción, cantidad, precio unitario, descuento y subtotal.
- `configuracion_presupuestos`: impuestos (16 % incluidos por omisión), vigencia por
  omisión (15 días), notas al pie y el último folio.

**Cuentas** (`TotalesPresupuesto`, solo enteros):
- Cada línea vale cantidad × precio − su descuento.
- La base es la suma de las líneas − el descuento general.
- **Desglosados:** impuesto = base × tasa; total = base + impuesto.
- **Incluidos:** total = base; se informa el impuesto que contiene, base − base ÷
  (1 + tasa).
- Nunca da negativos.

**Estados:**
- borrador → enviado → aceptado o rechazado → convertido.
- Vencido no se guarda: es un borrador o un enviado cuya vigencia pasó.
- Se edita mientras no se responda.
- Uno vencido no se acepta sin cambiar antes su vigencia.
- Solo un borrador se borra.

**Enviar:** por correo al cliente, con la marca del negocio. Responder le escribe al
negocio. También hay una vista para imprimir o guardar como PDF.

**Convertir** (exige además `ordenes.gestionar`): crea una orden de cobro pendiente al
precio del presupuesto.
- El descuento general pasa a la orden.
- Desglosados: el impuesto va como su propio concepto, para que la orden sume lo mismo.
- Los bonos van como producto: al pagarse se conceden como siempre. En citas no hay
  membresías (ADR 0130).
- Servicios y conceptos libres van como concepto escrito (`lineas_orden.descripcion`).
- Un artículo sale del inventario de la sede de la orden al pagarse
  (`lineas_orden.articulo_id`).
- Si era un contacto, se da de alta como cliente (o se usa el que ya tenga ese correo).
- Las citas se agendan aparte.

**Acceso:**
- Rutas `modalidad:citas`.
- Permisos `presupuestos.ver` y `presupuestos.gestionar`: los traen el administrador y
  recepción; el dueño, todos.
- Alcance por sucursal, como las ventas.
- En la web, «Presupuestos» va en Ventas, solo para negocios de citas.

## Consecuencias

- Un negocio de citas cotiza, comparte y cobra lo cotizado sin capturarlo dos veces.
- Cambiar los impuestos del negocio no altera los presupuestos ya hechos.
- Fuera de alcance por ahora: aceptación en línea por el cliente, agendar desde el
  presupuesto y la app móvil (la pantalla es de la web del negocio).

## Ajuste (2026-10-21)

- Al facturar la orden de un presupuesto con impuestos desglosados, la línea
  «Impuestos (16 %)» no se trata como un concepto: va un solo concepto con el total.
  Ver ADR 0179.
