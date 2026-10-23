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
  - ~~avisos push al equipo cuando llega un mensaje~~ (hecho, ver el ajuste);
  - ~~mandar y ver archivos~~ (hecho, ver el ajuste);
  - ~~contestar fuera de las 24 horas con plantillas del número del negocio~~ (hecho,
    ver el ajuste);
  - ~~Facebook Messenger~~ (hecho, ver el ajuste).
- Sin validar con Meta: probado con respuestas simuladas.

## Ajuste (2026-10-21)

- **Aviso push al equipo**: el evento `bandeja.mensaje_recibido` le llega a quien tiene
  `mensajes.atender` (`AvisosAlEquipo`) con la plantilla push de fábrica, que llega
  activa: «Mensaje nuevo por WhatsApp», «Lucía te escribió. Contéstale en Mensajes.».
  - Sale una vez por conversación, con el primer mensaje sin leer; una ráfaga no
    manda un aviso por mensaje. Leída la conversación, el siguiente vuelve a avisar.
  - Dice el canal y el nombre con que escribió (o «Alguien»), nunca su número ni lo
    que escribió.
  - Se puede apagar o cambiar en Comunicación → Automáticos.
- **Archivos**:
  - Lo que llega guarda su referencia en Meta (el id del medio de WhatsApp o la
    dirección del adjunto de Instagram), su tipo y su nombre. El archivo se baja en
    cola (`BajarAdjuntoBandeja`, con el negocio en su carga) al disco privado,
    `bandeja/{negocio}/…`.
    - El de WhatsApp se baja con la llave del número. Meta lo guarda 30 días.
    - Lo que pesa más de `bandeja.archivo_max_mb` (16 MB, de la plataforma) no se
      guarda: el panel dice que se abra en el teléfono.
  - Se ve solo por la API, con `mensajes.atender`. Una imagen común se abre; lo demás
    se descarga como `application/octet-stream`, así un HTML o un SVG nunca se
    ejecuta en el navegador. Si aún no se bajó, se intenta al pedirlo.
  - El equipo manda una imagen o un PDF, con pie, en las 24 horas:
    - **WhatsApp:** se sube al número del negocio y se manda como imagen o documento.
    - **Instagram:** solo imágenes. Meta la toma de una dirección de la plataforma,
      firmada y que vence en 15 minutos; apunta al archivo, porque Meta puede pedirlo
      antes de que el mensaje quede guardado. El pie sale como otro mensaje.
  - **Baja de datos (ARCO):** al atenderla se borran las conversaciones ligadas a su
    ficha, con sus archivos. Una conversación sin ligar no se puede saber de quién es.
  - **Acceso y portabilidad (ARCO):** `POST /mi/datos` incluye esas conversaciones con
    sus mensajes. De paso, la descarga incluye lo demás que quedaba fuera (niveles,
    grupos, invitados, reseñas, compras del mostrador, apartados, presupuestos,
    puntos y canjes, su solicitud de informes, seguimientos y los identificadores de su
    navegador guardados para medir campañas). La baja vacía también la fecha de
    nacimiento, el género, «cómo nos conoció», el identificador del sistema anterior,
    los datos de su prospecto, el contacto de sus presupuestos, el texto de sus
    reseñas (la calificación sigue en los promedios) y los identificadores de su
    navegador; sus conversiones pendientes ya no se mandan (`omitido`,
    `baja_de_datos`).
- **Plantillas fuera de las 24 horas (WhatsApp)**:
  - El panel lee las plantillas aprobadas de la cuenta de WhatsApp del negocio
    (`message_templates`), guardadas 10 minutos. Solo ofrece las que puede llenar: de
    texto, con variables `{{1}}`, `{{2}}`…, sin imagen ni variables en el
    encabezado, y sin botones con datos (enlace con variable, código, flujo).
  - Las plantillas se crean y Meta las aprueba en su administrador, no aquí. Son
    del número del negocio, no las de la plataforma (ADR 0190).
  - Se mandan también fuera de las 24 horas. En la conversación queda el texto tal
    como le llegó. El panel avisa que Meta cobra el mensaje a la cuenta de WhatsApp
    del negocio, según su categoría.
  - Instagram no tiene plantillas: ahí se espera a que vuelva a escribir.
- **Facebook Messenger**:
  - **Conexión:**
    - La página de Facebook del negocio se conecta con el inicio de sesión de Facebook
      de la misma app de Meta. Pide `pages_show_list`, `pages_manage_metadata` y
      `pages_messaging`, nada más.
    - Del código sale una llave larga de quien conecta. De ella salen las llaves de
      sus páginas, que no vencen.
    - Con una sola página se conecta. Con varias, el panel pide elegir una.
      - Sus llaves esperan 10 minutos, cifradas y ligadas al negocio, bajo una
        `seleccion` de un solo uso.
      - Una página conectada a otro negocio aparece pero no se elige.
    - Al conectar, la app se suscribe a `messages` y `message_echoes` de la página.
    - Al desconectar, se da de baja de la página.
  - **Recibir y contestar:**
    - Avisos en `/webhooks/messenger`, firmados con la app de Meta y con el mismo
      token de verificación. Tienen el mismo formato que Instagram y comparten su
      código.
    - El nombre de quien escribe se lee de su perfil.
    - Se contesta en 24 horas con `messaging_type: RESPONSE`, hasta 2,000
      caracteres.
    - Una imagen o un PDF se mandan por la dirección firmada; el pie sale aparte.
  - **Encendido:** apagado hasta que Meta apruebe los permisos de páginas de la app
    (`BANDEJA_MESSENGER=true`).
- Sin validar con Meta: todo lo de este ajuste se probó con respuestas simuladas.

## Ajuste (2026-10-21): contestar hasta 7 días por Instagram y Messenger

- Con `BANDEJA_AGENTE_HUMANO=true` (cuando Meta aprueba el permiso «Human Agent» de la
  app), una conversación de Instagram o Messenger se puede contestar hasta 7 días
  después del último mensaje de la persona. Pasadas las 24 horas, cada texto o archivo
  sale con `messaging_type: MESSAGE_TAG` y `tag: HUMAN_AGENT`, y el panel avisa que se
  contesta como persona del equipo y que no van promociones (`como_agente`).
- WhatsApp no tiene esa etiqueta: fuera de las 24 horas sigue con plantillas.
- Apagado por omisión. El formato de la etiqueta en la API de Instagram no se probó
  con Meta; está documentado para Messenger.

## Ajuste (2026-10-23): mensajes anteriores por tandas

- Una conversación abre con sus 100 mensajes más recientes y `hay_anteriores`. Antes se
  mostraban los últimos 200 y lo anterior no se podía ver.
- «Cargar mensajes anteriores» pide la tanda que va antes del primero que se ve, por
  fecha y luego por id (dos mensajes del mismo segundo no se saltan ni se repiten).
  Usa el índice `(conversacion_id, enviado_en)`.
- El refresco de cada 20 segundos trae solo la tanda reciente: lo que ya se cargó hacia
  atrás se conserva y la lectura no salta al cargar más.
