# ADR 0194 — Productos al reservar, para recoger en el mostrador

Estado: Aceptado (2026-10-20).

## Contexto

El comparativo propuso ofrecer productos al reservar, como agua o calcetines
antiderrapantes, para recogerlos al llegar a la clase. Los artículos del mostrador
(ADR 0139) se venden solo en caja, con stock por sede en el ledger de inventario. No
hay venta en línea de artículos.

## Decisión

- **El negocio marca qué artículos se ofrecen** («Ofrecerlo al reservar una clase», en
  el editor del artículo). Solo en negocios de clases.
- **Se aparta, no se compra en línea.** Quien tiene su lugar confirmado en una clase que
  aún no empieza aparta lo que se ofrece en la sede de esa clase. Lo paga al recogerlo
  en el mostrador.
- **No se aparta más de lo que hay**: lo disponible es el stock de la sede menos lo ya
  apartado ahí. El artículo se bloquea al apartar. Hasta 5 de cada artículo por clase;
  apartar otra vez lo mismo suma.
- **El cliente lo quita** antes de la clase y **el equipo, cuando sea**.
- **Recepción lo ve en la lista de la clase**, por persona, con «Cobrar en el
  mostrador». Ese botón abre el mostrador con los artículos en el carrito, la sede de
  la clase y la persona como compradora. La venta (`POST /pos/ventas` con `apartados`)
  los marca entregados con su venta, en la misma transacción. Solo se entregan los de
  esa persona, en esa sede, aún apartados.
- **El inventario se mueve con la venta**, no al apartar: lo apartado y no recogido no
  descuadra el stock.
- El apartado guarda el precio que vio el cliente. Se cobra el precio vigente del
  mostrador, que el mostrador revisa como siempre.

## Consecuencias

- Lo que nadie recoge queda apartado hasta que el equipo lo cancela; mientras tanto
  resta de lo disponible para apartar.
- En la app móvil aún no se aparta; se hace desde la cuenta en la web.
