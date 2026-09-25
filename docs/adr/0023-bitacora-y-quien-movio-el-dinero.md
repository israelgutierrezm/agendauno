# ADR 0023 — Bitácora completa, quién movió el dinero y baja lógica de catálogos

Estado: Aceptado (2026-09-24). Continúa el ADR 0022 (bajas lógicas de personas y
usuarios).

## Contexto

El dueño del producto pidió control de usuarios eliminados, pagos realizados y
pagos eliminados/cancelaciones "por fecha y quién realizó los movimientos", y bajas
lógicas en todo el sistema. Faltaba:
- `pagos` no guardaba quién cobró y la liquidación en caja ni siquiera creaba un pago
  (un reporte de cobros no veía el efectivo);
- la bitácora solo tenía 8 acciones, sin filtros por fecha ni usuario, tope de 100;
- 9 pantallas de catálogo y configuración borraban de verdad.

## Decisiones

- **Columnas solo con uso operativo; el resto en la bitácora** (DB-07 de la
  auditoría): `pagos.registrado_por` (quién registró el cobro en caja o el alumno que
  pagó en línea; vacío = pago automático o cliente sin cuenta) y
  `ordenes.cancelada_en` / `cancelada_por`. Devoluciones y ventas de mostrador ya
  guardaban su actor.
- **La liquidación en caja crea su pago** (`CobrarOrdenTenant` con proveedor manual y
  el método: efectivo, transferencia → SPEI, ventanilla), con quién lo cobró; si
  había un pago en línea abierto, se cierra primero (si ya se pagó, no se cobra dos
  veces). Queda en bitácora (`pago.registrado`).
- **Corte de caja** (`GET /pagos/movimientos`): cobros, devoluciones, ventas de
  mostrador y cancelaciones en un rango de fechas (zona del negocio), filtrable por
  persona del equipo y tipo, con totales (cobrado, devuelto, neto, por método y por
  persona) y CSV (con protección contra fórmulas).
- **Bitácora** (`GET /auditorias`): filtros por fechas, persona del equipo
  (incluidos los dados de baja), categoría (equipo, alumnos, pagos, membresías,
  catálogo, privacidad, configuración), acción, entidad y texto; paginada; CSV;
  descripción legible por asiento ("Dio de baja a Ana López", "Registró un cobro de
  $899.00 MXN"). Nuevos asientos: invitación y cambio de roles del equipo,
  configuración de pasarelas (solo los NOMBRES de las llaves), eliminaciones y
  restauraciones de catálogo.
- **Baja lógica de catálogos y configuración** (`SoftDeletes` + `eliminado_por`):
  promociones, mensajes automáticos, clases recurrentes, recursos, automatizaciones,
  cupos por canal, días cerrados, webhooks y asignaciones de sede. Eliminar los oculta
  y dejan de usarse (una promoción eliminada no se aplica; un webhook eliminado no
  recibe avisos ni reintentos); la bitácora guarda qué eran (nunca el secreto de un
  webhook). **Volver a crearlos con su misma clave los restaura** (código de promoción,
  evento + canal del mensaje, oferta + canal del cupo, fecha del día cerrado, usuario +
  sede de la asignación).
- Siguen borrándose de verdad, a propósito: sesiones/tokens (seguridad), lo que la
  cancelación ARCO exige eliminar, y el horario de atención que se reemplaza completo.

## Consecuencias

- Los reportes históricos usan `withTrashed()` en las relaciones hacia catálogos
  eliminados (recurso de una sesión, clase recurrente de un grupo).
- Todo registro nuevo que "se elimine" debe usar `EliminacionesTenant` (baja lógica +
  quién + bitácora) en lugar de `delete()` directo.
