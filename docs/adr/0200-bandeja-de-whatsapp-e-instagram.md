# ADR 0200 — Bandeja de WhatsApp e Instagram

Estado: Aceptado (2026-10-20). Sin validar con Meta: hace falta que la plataforma sea
Tech Provider y que Meta apruebe los permisos de la app.

## Contexto

Los avisos y campañas salen del número de la plataforma (ADR 0069, 0083 y 0190). Lo
que la gente contesta ahí no lo lee nadie: se le responde una vez con a quién
dirigirse. Fitune y Atom tienen una bandeja: el negocio lee y contesta su propio
WhatsApp y su Instagram desde el panel. El dueño pidió primero las plantillas por
negocio (ADR 0190) y la bandeja después.

## Decisión

- **Cada negocio conecta lo suyo**, no el número de la plataforma:
  - **WhatsApp**: su número de WhatsApp Business con el registro insertado de Meta
    (Embedded Signup). La plataforma es Tech Provider: el código que da Meta se canjea
    por una llave de la app de la plataforma, que se suscribe a su cuenta y registra el
    número con un PIN de 6 dígitos (guardado cifrado).
  - **Instagram**: su cuenta profesional con el inicio de sesión de Instagram. Se pide
    solo leer el perfil y manejar los mensajes. La llave dura 60 días;
    `agendauno:renovar-instagram` la renueva cada día cuando le quedan menos de 15. Si
    ya no sirve, la cuenta queda en «reconectar».
  - Lo conecta quien tiene `integraciones.configurar`, con la función `mensajes` del
    plan. La vuelta de Instagram no canjea nada: lleva el código a la bandeja y la web
    lo confirma con la sesión (como los calendarios, ADR 0199).
- **En el control plane** (`canales_mensajeria`): Meta avisa a una sola URL de la
  plataforma, así que el id del número o de la cuenta dice de qué negocio es cada
  mensaje. Un número o una cuenta solo puede estar en un negocio. Las llaves van
  cifradas y nunca salen en una respuesta.
- **Avisos**:
  - WhatsApp llega al webhook de siempre (`/webhooks/whatsapp`). Lo que es de un número
    de la bandeja se guarda en su negocio y no se contesta solo. Lo demás sigue como
    antes (estados y respuesta automática del número de la plataforma). La firma puede
    ser de la app del número de la plataforma o de la app de la bandeja.
  - Instagram llega a `/webhooks/instagram`, con su token de verificación y la firma de
    la app.
  - Un aviso repetido no duplica nada (el id del mensaje es único).
  - Lo que el negocio contesta desde la app de Instagram también se guarda.
- **En la base del negocio**: `conversaciones` (una por persona y canal) y
  `mensajes_conversacion`. Se guarda lo que escribió: es su bandeja, como su correo.
  De los adjuntos solo se guarda el tipo y el pie de foto, no el archivo.
- **Contestar**: con texto libre, solo en las 24 horas desde el último mensaje de esa
  persona (regla de Meta). Fuera de ese plazo el panel lo explica y no deja escribir.
  Las plantillas del ADR 0190 son del número de la plataforma, no del negocio.
- **Atender** pide `mensajes.atender`. Lo tienen el dueño, la administración y
  recepción, y se puede dar en un rol propio.
- La conversación se archiva y se reabre sola si vuelve a escribir. Se vincula a mano
  con la ficha de un cliente: no se busca a nadie por su número de forma automática.
- **Panel**: «Mensajes» en Captación, con la lista y la conversación (en el teléfono,
  una a la vez). Se refresca cada 20 segundos.
- **Bitácora**: `bandeja.canal_conectado` y `bandeja.canal_desconectado`.

## Consecuencias

- Mientras la plataforma no tenga la app de Meta aprobada (`BANDEJA_*`), nada cambia
  para los negocios.
- La CSP permite los marcos de facebook.com que usa el registro insertado.
- Pendiente:
  - avisos push al equipo cuando llega un mensaje;
  - mandar y ver archivos;
  - contestar fuera de las 24 horas con plantillas del número del negocio;
  - Facebook Messenger.
- Sin validar con Meta: probado con respuestas simuladas.
