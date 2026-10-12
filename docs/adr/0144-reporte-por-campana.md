# ADR 0144 — Reporte interno por campaña

Estado: Aceptado (2026-10-12). Último bloque del pedido de GA4 y Meta: «Amplía el reporte
interno por campaña con solicitudes, nuevos compradores, compras confirmadas,
devoluciones y reservas; separa adquisición inicial de renovaciones».

## Contexto

- No había ningún reporte por campaña. El embudo del negocio (ADR 0122) daba totales y
  el de la plataforma (C08) daba totales por producto.
- La atribución ya se guarda con cada registro (ADR 0142): la solicitud, la orden, el
  registro del negocio y el contexto de cada persona.

## Decisión

**Del negocio** (Reportes → Embudo → «Por campaña»; `GET /reportes/campanas`, con
`facturacion.ver` y `reportes_avanzados`, últimos 30 días por omisión, hasta un año).

- Cada fila es una campaña acreditada (`campana`, `fuente`, `medio`; el sitio si solo
  hay referente) o «sin atribución».
- Columnas:
  - **solicitudes**: prospectos de sus formularios en el periodo;
  - **compras**: órdenes con monto pagadas en el periodo que no son renovación, aunque
    después se devuelvan. La devolución va aparte, como en GA4;
  - **compradores nuevos**: personas cuya primera compra cae en el periodo;
  - **devoluciones**: reembolsos aplicados en el periodo;
  - **primeras reservas**: la primera reserva confirmada de cada persona;
  - **renovaciones**, aparte: órdenes de renovación pagadas, para no inflar la
    adquisición.
- La campaña de cada cosa se busca en este orden:
  1. la de su registro (la solicitud o la orden);
  2. la más reciente de la persona;
  3. la de su solicitud convertida.

  Es la misma regla con que se mandan las conversiones (ADR 0143).
- Montos en unidades menores y en la moneda del negocio. Fechas en su zona.

**De la plataforma** (Superadmin → Embudo → «Por campaña»; `campanas` en
`GET /plataforma/embudo`):

- negocios registrados en el periodo, sin demos, por producto y por la campaña de su
  registro;
- cuántos tuvieron su primera operación real y cuántos pagaron su primera suscripción
  (sin timbres ni cargos en cero).

**Gasto publicitario.**

- No se captura ni se importa (fuera de este pedido), así que no se calculan costo por
  solicitud, costo por compra ni retorno.
- Si algún día se captura, cada métrica dirá su denominador. Nunca se inventa un gasto.

**No es la atribución de Google ni la de Meta.** Cada proveedor tiene sus ventanas y sus
modelos (vista, clic, datos agregados), así que las cifras no tienen por qué coincidir. El
reporte lo dice.

## Consecuencias

- Sin migraciones: usa `contextos_medicion`, `prospectos`, `ordenes`, `reembolsos` y
  `reservas` del negocio, y `contextos_medicion_plataforma` y `cargos_renta`.
- Lo que llegó antes de esta versión no tiene campaña: queda «sin atribución».
- El cálculo recorre las órdenes del periodo en PHP (un negocio tiene cientos o pocos
  miles al mes). Si crece, se puede guardar la campaña acreditada en la orden.
