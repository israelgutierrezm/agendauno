# ADR 0159 — Retención por segmentos, con acción

Estado: Aceptado (2026-10-17). Continúa el seguimiento útil (M09/C05/C06) y las
difusiones segmentadas.

## Contexto

Renovaciones mostraba planes por vencer y vencidos. El seguimiento diario crea una
tarea por señal al aparecer (su primera clase, plan por vencer o vencido, sin asistir,
compró y no ha reservado). Faltaba:

- ver a todo un grupo de una vez y actuar sobre todos: escribirles o crear su
  seguimiento;
- señales que no existían:
  - «vino y no ha comprado» (la clase de prueba);
  - «su pago falló»;
  - «paquete por vencer con saldo sin usar»;
  - en citas, «ya debería regresar» según su servicio.

## Decisión

- **Segmentos** (`SegmentosRetencionTenant`), en vivo y sin listas guardadas. Solo
  personas con perfil de miembro, no archivadas:
  - `sin_asistir`: con plan en vigor y sin venir desde hace
    `seguimiento.dias_sin_asistir` días o más.
  - `saldo_por_vencer`: vence en `membresias.dias_por_vencer` días y le queda saldo
    (el disponible del libro mayor, sin las retenciones).
  - `vino_sin_comprar` (solo clases): vino en los últimos
    `seguimiento.dias_vino_sin_comprar` días (parámetro nuevo, 30 por omisión). Nunca
    ha comprado un plan ni tiene uno.
  - `pago_fallido`: un cobro automático en mora o con la membresía suspendida.
  - `debe_regresar` (solo citas):
    - el servicio tiene `regreso_dias` («suele regresar cada…», en el catálogo);
    - su último servicio presente fue hace más días que eso;
    - no tiene otra cita por delante.
- **Ver y actuar**:
  - `GET /retencion/segmentos`: cada grupo con cuántos son y cuántos aceptan
    promociones.
  - `GET /retencion/segmentos/{clave}`: quiénes son, con el porqué en palabras del
    negocio y si ya tienen seguimiento.
  - `POST /retencion/segmentos/{clave}/seguimientos`: una tarea por persona, con el
    mensaje sugerido de su motivo. Aplica las reglas del seguimiento diario: sin
    repetir una abierta o reciente, y sin tocar a quien no desea promociones si el
    motivo es comercial.
  - **Escribirles**: los mismos grupos son segmentos de las difusiones
    (`SegmentoComunicacion`), con la misma clave. Las difusiones solo llegan a quien
    acepta promociones.
  - Las difusiones ofrecen solo los segmentos de la modalidad del negocio.
- **Motivos nuevos** del seguimiento: `saldo_por_vencer`, `vino_sin_comprar`,
  `pago_fallido` y `debe_regresar`. Su pago falló es de servicio, no comercial.
- **Alcance**: todo el negocio. Quien imparte no los ve (403), como en Renovaciones.
- **Web**: Renovaciones tiene dos vistas, «Por vencer y vencidas» y «Para recuperar»
  (`?vista=recuperar`). «Escribirles» abre Comunicaciones con el grupo elegido.
- **WhatsApp**: el aviso del canal dice que solo envía avisos; las respuestas de los
  clientes no llegan a la plataforma. No se vende como bandeja de conversaciones.

## Consecuencias

- Migración de negocio 000149 (`ofertas.regreso_dias`).
- Los segmentos se calculan al pedirlos. En un negocio muy grande, «paquete por vencer
  con saldo» consulta el libro mayor por cada plan en esa ventana.
- El seguimiento diario no cambia: estos segmentos son para actuar sobre todo un grupo
  cuando el negocio lo decide.
