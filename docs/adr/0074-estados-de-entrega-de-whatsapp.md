# ADR 0074 — Estados de entrega de WhatsApp

Estado: Aceptado (2026-09-30).

## Contexto

Con los ADR 0069 y 0071, «enviado» solo significa que Meta aceptó el mensaje. Si
el número no tiene WhatsApp, o la plantilla no se pudo entregar, nadie se enteraba:
el aviso quedaba como enviado. Meta informa la entrega, la lectura y las fallas por
un webhook, que no se procesaba.

## Decisión

- **Registro de lo enviado** (`whatsapp_envios`, control plane):
  - Cada envío guarda el `wamid` que devuelve Meta, de dónde salió y a qué
    corresponde:
    - `mensaje`: el aviso de un negocio a su cliente, con el negocio y el mensaje;
    - `aviso_dueno`: el aviso de la plataforma al dueño.
  - El webhook es uno solo para toda la plataforma y no dice de qué negocio es el
    mensaje. Esta tabla lo resuelve sin buscar en cada base.
- **Webhook** `GET|POST /api/v1/webhooks/whatsapp`:
  - **Verificación (GET):** Meta manda `hub.mode=subscribe` y el token de
    verificación. Si coincide, se responde el `hub.challenge`.
    - El token se genera una vez (40 caracteres) y el superadministrador lo ve
      para copiarlo en Meta.
  - **Avisos (POST):** van firmados con `X-Hub-Signature-256`, un HMAC del cuerpo
    con el App Secret de la app de Meta.
    - Se compara con `hash_equals`. Una firma que no coincide responde 403.
    - Sin App Secret guardado, en producción se rechaza todo; fuera de producción
      se acepta, para probar.
  - Límites por IP con contador propio: 60/min para verificar y 600/min para avisos.
- **Estados:**
  - Meta manda `sent`, `delivered`, `read` y `failed`, que aquí son enviado,
    entregado, leído y fallido, en ese orden.
  - Meta no garantiza el orden de llegada. Un estado igual o anterior al que ya se
    tiene se ignora; un «enviado» tardío no borra un «leído».
  - Un `wamid` que no está registrado (de otra app o ya purgado) se ignora.
  - **Entregado:** llena `entregado_en`. **Leído:** llena `leido_en` y, si faltaba,
    `entregado_en`. El estado del mensaje sigue en «enviado».
  - **Fallido:** el mensaje o el aviso pasa a `fallido` con el motivo de Meta,
    «WhatsApp no entregado (código): detalle».
    - Los intentos quedan en el máximo para que no se reintente, porque volver a
      mandarlo cuesta y fallaría igual.
- **Conexión (superadministrador):** en Configuración → «WhatsApp», bajo el número
  y el token, «Webhook de estados» muestra:
  - la dirección y el token de verificación, para copiarlos en Meta;
  - el App Secret, que se guarda cifrado y no se vuelve a mostrar, igual que el
    token.
- **Dónde se ve:**
  - en la salida de Comunicaciones del negocio, un WhatsApp enviado dice
    «Entregado» o «Leído», y uno fallido muestra el motivo;
  - en la ficha del negocio del superadministrador, los avisos al dueño dicen lo
    mismo.

## Consecuencias

- Un número sin WhatsApp ya no pasa como «enviado»: queda fallido con el motivo, y
  el negocio puede pedir otro dato o avisar por otro canal.
- Hay que configurar el webhook en la app de Meta y suscribirse al campo
  `messages`. Sin eso, todo sigue funcionando como antes, sin estados de entrega.
- `whatsapp_envios` crece con cada envío; se limpia pasado su plazo (ADR 0079).
- Los mensajes que entran (respuestas de los clientes) llegan al mismo webhook y se
  ignoran; atenderlos sería otro trabajo.
