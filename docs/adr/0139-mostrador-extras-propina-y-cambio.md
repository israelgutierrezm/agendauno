# ADR 0139 — Mostrador: extras, propina y cambio

Estado: Aceptado (2026-10-12). Pedido del dueño sobre el punto de venta:

- poner cuánto dejan de propina;
- cargar extras a un producto, como en un lugar que vende proteína preparada: fresas
  $15, arándanos $15, medio plátano $5, leche de soya $10;
- poner cuánto ingresó, que se interpretó como lo que entregó el cliente en efectivo,
  para darle su cambio.

## Decisión

**Extras de un artículo.**

- Tabla `extras_articulo` (nombre, precio, activo, orden). Se edita con el producto en
  Inventario.
- Quitar un extra lo apaga: las ventas ya hechas guardan los suyos.
- No llevan inventario propio (son agregados, no productos).
- En la venta, cada línea guarda los extras que llevó, congelados con su nombre y su
  precio de ese día (`lineas_venta_pos.extras`, `extras_minor`). El subtotal es
  (precio + extras) × cantidad.
- La API rechaza un extra de otro producto o apagado.
- En el Mostrador, tocar un producto con extras abre un diálogo: un toque por extra,
  sin depender del mouse. La misma proteína con y sin fresas son dos líneas.

**Propina.**

- `ventas_pos.propina_minor`, opcionalmente para alguien del equipo
  (`propina_para_id`, de `GET /pos/propina-para`: el equipo activo, no los clientes).
  El ADR 0145 quitó el «para quién»: la columna queda solo por lo ya registrado.
- Va aparte de lo vendido: `total_minor` sigue siendo el ingreso del negocio, y los
  reportes de ventas no la cuentan.
- En el corte de caja va aparte, con su total y por forma de pago (`propinas_minor`,
  `propinas_por_metodo`): sí está en la caja, pero no es del negocio.
- En el Mostrador: sin propina, 10 %, 15 %, 20 % u otra cantidad, y para quién.

**Recibido y cambio.**

- En efectivo, «Recibe» guarda lo que entregó el cliente (`recibido_minor`). El cambio
  es lo recibido menos lo vendido y la propina.
- Si no alcanza, la API no cobra (422) y la pantalla dice cuánto falta.
- La respuesta trae `cobrado_minor` y `cambio_minor`, y el aviso de venta dice el
  cambio.

## Consecuencias

- Migración de negocio 000131. Las ventas anteriores quedan sin propina ni extras.
- La propina de una cita se cobra con su servicio al terminarla (ADR 0158).
- La propina no se reparte ni se paga sola: el corte dice cuánto hay y, si se eligió,
  de quién es.
