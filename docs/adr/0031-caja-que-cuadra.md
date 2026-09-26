# ADR 0031 — Caja y reportes que cuadran (fase 1, punto 1.7)

Estado: Aceptado (2026-09-25).

## Contexto

El corte de caja fechaba cada cobro cuando se inició (un pago en línea iniciado ayer y
confirmado hoy salía ayer), calculaba los totales solo sobre las primeras 2,000 filas
de cada tipo (sin avisar que se cortó), sumaba monedas distintas como una sola y el
CSV exportaba solo lo que se veía, sin totales.

## Decisiones

- **Fechas efectivas**: `pagos.aprobado_en` (cuándo entró el dinero; se fija al
  aprobarse por cualquier camino: caja, webhook, suscripción) y
  `reembolsos.aplicado_en` (cuándo se devolvió, desde el 1.1). El corte usa esas
  fechas en la zona horaria del negocio. Lo anterior se llenó con la fecha de creación
  del registro (en caja coincide; en línea puede adelantarse unos minutos).
- **Totales del rango completo**, calculados en la base (agregados SQL), no sobre las
  filas mostradas. La lista trae a lo más `limite` filas por tipo, las más recientes,
  y avisa si se cortó (`meta.truncado`).
- **Por moneda**: `totales_por_moneda` (cobrado, devuelto, neto, por cobrar, por
  método y por persona) nunca mezcla monedas; `totales` sigue siendo el de la moneda
  principal por compatibilidad.
- **Por cobrar**: las compras del rango que siguen pendientes de pago.
- **El CSV coincide con el reporte**: exporta TODOS los movimientos del rango y los
  mismos totales por moneda al final.
- **Rastreable**: cada movimiento lleva su referencia y la de su operación de origen
  (orden y pago).

## Consecuencias

- Las citas aún fijan MXN al crear su orden; un negocio en otra moneda necesitará
  la moneda por servicio (fuera de este punto).
