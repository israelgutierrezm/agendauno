# ADR 0196 — Asistente con IA sobre los datos del negocio

Estado: Aceptado (2026-10-20). Sin validar con la API real de Anthropic.

## Contexto

El comparativo encontró que la IA de la competencia es más discurso que producto: Atom
vende un «analista de datos» y Buq lo anunció sin tenerlo. Recomendó un asistente real
sobre nuestros datos, con dos condiciones:

- preguntas en lenguaje natural sobre ocupación, ingresos y bajas;
- acciones con revisión antes de aplicarlas, respetando permisos y el ledger.

## Decisión

- **El modelo es Claude**, por la API de Anthropic, llamado por HTTP sin paquete nuevo.
  La llave es de la plataforma (`ASISTENTE_ANTHROPIC_KEY`); el modelo, configurable
  (`claude-sonnet-5-5` por omisión).
- **Lo enciende el superadmin** (`asistente.activo`, apagado por omisión: cada pregunta
  cuesta). Cada negocio tiene un tope de preguntas al día (`asistente.preguntas_por_dia`,
  40; 0 = sin asistente).
- **Quién**: quien ve las finanzas (`facturacion.ver`), en negocios con reportes
  avanzados.
- **Contesta con los reportes del negocio** como herramientas: negocio, sucursales,
  demanda, tendencias, cohortes y segmentos de clientes.
  - Son los mismos números de la pantalla de Reportes: se llama a sus controladores con
    la sesión de quien pregunta, así respeta su alcance por sede.
  - Cada herramienta revisa el permiso y la función del plan.
  - Solo cifras agregadas: ninguna devuelve nombres, correos ni teléfonos de clientes.
    El reporte del equipo no se usa.
- **Las acciones solo se proponen** (`proponer_accion`): generar la agenda de clases de
  un periodo desde las plantillas de horario, o mandar una campaña a un segmento por
  correo o en la app.
  - La propuesta vuelve cifrada y firmada (APP_KEY), con quién la pidió, de qué negocio
    y su vencimiento (30 minutos).
  - La persona la revisa y la aplica; al aplicarla se vuelven a revisar sus permisos y
    queda en la bitácora (`asistente.accion_aplicada`).
  - Ninguna acción toca dinero ni créditos.
- **Sin memoria del lado del servidor**: la web manda la conversación (hasta 20
  mensajes) en cada pregunta. No se guardan preguntas ni respuestas.

## Consecuencias

- Lo que la persona escriba en su pregunta llega al modelo tal cual: el aviso de la
  pantalla pide no escribir datos personales de clientes.
- Agregar una herramienta es exponer un reporte existente; agregar una acción es un
  tipo de propuesta más con su revisión de permisos.
- Sin validar con la API real: probado con respuestas simuladas.
