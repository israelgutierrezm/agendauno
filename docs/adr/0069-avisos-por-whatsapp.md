# ADR 0069 — Avisos por WhatsApp (Meta Cloud API)

Estado: Aceptado (2026-09-29).

## Contexto

Los clientes de barberías, salones y estudios leen WhatsApp más que el correo. Pero
cada mensaje que inicia el negocio cuesta (Meta cobra por plantilla entregada), y el
dueño de la plataforma debe poder apagarlo si el costo no conviene. Apagado, los
avisos deben seguir por correo y notificación push, y los negocios no deben ver la
opción.

## Decisión

- **Proveedor:** API de WhatsApp de Meta (Cloud API) directamente, sin
  intermediario. Hay un solo número de WhatsApp Business para toda la plataforma,
  igual que FCM para push.
- **Interruptor del superadministrador:**
  - Está en Configuración → «WhatsApp» (`GET/PUT /plataforma/whatsapp`), en el
    apartado «De los negocios a sus clientes» (`negocios`). Desde el ADR 0070 hay
    otro apartado, con los dueños, que se enciende por separado sobre la misma
    conexión.
  - Guarda si está encendido, el identificador del número (Phone number ID) y el
    token de acceso. Todo queda cifrado en `configuracion_plataforma`.
  - El token nunca se devuelve; si se deja vacío, se conserva el anterior.
  - `POST /plataforma/whatsapp/prueba` manda la plantilla de muestra `hello_world`.
  - La versión de la Graph API y la lada por omisión van en
    `config/agendauno.php` (`WHATSAPP_GRAPH_VERSION`, `WHATSAPP_LADA`).
- **Apagado (o sin número o token), no existe para los negocios:**
  - `CanalComunicacion::disponibles()` no incluye `whatsapp`;
  - Automáticos no muestra el canal ni los avisos que el negocio ya tenía;
  - la página de agendar y «Mi privacidad» no lo ofrecen;
  - no se generan mensajes;
  - los que estaban en cola se marcan `descartado` sin enviarse.
- **Texto fijo:** Meta solo deja iniciar una conversación con plantillas aprobadas.
  - `PlantillasWhatsApp` define un aviso por evento: confirmada, lugar apartado,
    recordatorios de 24 h y 2 h, reprogramada, cancelada y cancelada por el negocio.
  - Cada aviso usa los mismos marcadores que las demás plantillas. Para Meta se
    vuelven `{{1}}`, `{{2}}`, … en orden.
  - El superadministrador ve el nombre y el texto exactos para registrarlos (categoría
    Utilidad, español de México).
  - El negocio solo enciende o apaga cada aviso, y solo al cliente (no al equipo).
- **Consentimiento:** Meta pide que la persona acepte recibir los avisos.
  - `personas.whatsapp_aceptado_en` guarda cuándo lo aceptó; sin eso no se le
    manda nada.
  - Se acepta al agendar en la página pública (casilla que aparece al escribir el
    celular) o en «Mi privacidad», en la web o la app. Ahí también se retira.
  - Si el cliente lo pide en persona o por teléfono, lo marca el equipo: en la
    ficha de recepción, al darlo de alta o al agendarle una cita con cliente nuevo
    (`acepta_whatsapp` en `POST/PUT /miembros`, con `miembros.gestionar`). Queda
    en la bitácora quién lo marcó o lo retiró.
  - Solo se ofrece si el negocio tiene algún aviso por WhatsApp encendido.
  - La baja de datos borra el consentimiento, y la descarga de datos lo incluye.
- **Envío:**
  - El mensaje guarda el número normalizado (solo dígitos con lada; sin lada se
    asume la de México) y, en `mensajes.parametros`, la plantilla y sus valores.
  - Meta no acepta valores vacíos ni con saltos de línea: se limpian.
  - El relay lo manda con los reintentos de siempre. Si se agotan, avisa a la
    plataforma (token vencido, plantilla sin aprobar).
- **Fuera:** los envíos masivos no van por WhatsApp. Una promoción necesitaría
  plantillas de marketing, que cuestan más.

## Consecuencias

- El dueño de la plataforma controla el costo con un interruptor. Apagarlo no deja
  a nadie sin avisos, porque el correo y el push siguen igual.
- «Enviado» significa que Meta aceptó el mensaje. La entrega y la lectura llegan
  por el webhook de estados de Meta, que aún no se procesa. Un número sin WhatsApp
  solo se nota ahí.
- Todos los negocios usan el mismo número. Un número propio por negocio
  (Embedded Signup) o cobrar el WhatsApp como extra del plan es trabajo aparte.
- Cambiar el texto de un aviso exige registrar una plantilla nueva en Meta y
  cambiar su nombre en `PlantillasWhatsApp`.
