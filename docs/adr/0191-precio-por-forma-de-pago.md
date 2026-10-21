# ADR 0191 — Precio por forma de pago (descuento de contado)

Estado: Aceptado (2026-10-20).

## Contexto

En el comparativo, Atom tiene precio por SPEI y los estudios lo simulan duplicando
paquetes. En México es común dar un descuento a quien paga en efectivo o por
transferencia, porque la terminal cobra comisión. El comparativo lo dejó como mejora
«si los estudios lo piden».

## Decisión

- **Un porcentaje por negocio**, no un precio por producto: `ventas.descuento_contado_pct`
  (Configuración → Reglas del negocio → Cobros en caja). Va de 0 a 30; con 0, todo
  cuesta lo mismo con cualquier forma de pago.
- **Solo al cobrar en caja** (`POST /ordenes/{orden}/liquidar`) todo en efectivo o todo
  por transferencia. No aplica:
  - con la terminal (tarjeta) ni en ventanilla;
  - con varias formas de pago, porque las partes ya sumaban el total completo;
  - a los pagos en línea, cuyo monto se fija al abrir el cobro en la pasarela.
- **Una vez y a la vista.** Dentro de la misma transacción del cobro, con la orden
  bloqueada:
  - el total baja;
  - el descuento suma al de la orden (`descuento_minor`, que ya usan el recibo y la
    factura);
  - queda aparte en `descuento_contado_minor` (migración 000181), para no aplicarlo dos
    veces y para que se vea en la venta.
  - El pago se registra por lo que de verdad se cobró. La bitácora del cobro guarda el
    descuento.
- **La caja lo dice antes de cobrar**: la sesión trae `estudio.descuento_contado_pct` y
  el diálogo de cobro muestra el precio de contado y el de antes.

## Consecuencias

- Se redondea a favor del cliente: el descuento se trunca a centavos.
- El descuento se calcula sobre todo lo que lleva la orden, cuotas incluidas. Si un
  negocio quiere precios distintos por producto, hace falta otra regla.

## Ajuste (2026-10-21)

- El descuento se aplicaba también al cobrar una cita (`POST /reservas/{r}/cobro`) y
  en «vender y cobrar» (`POST /ordenes` con `liquidar`). Ninguna de esas pantallas lo
  mostraba, así que el ticket, el cambio y la caja no cuadraban. Ahora solo lo aplica
  `POST /ordenes/{orden}/liquidar` (`liquidar(..., conDescuentoContado: true)`).
- Anular un cobro de contado devuelve la orden a su precio y quita el descuento. Si se
  cobra otra vez de contado, se vuelve a descontar.
- Corregir un cobro de contado a una forma que no es de contado (tarjeta, ventanilla)
  se rechaza: cambiaría el precio. Se anula y se cobra de nuevo.
