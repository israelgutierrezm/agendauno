# ADR 0083 — WhatsApp negocio por negocio y respuesta automática

Estado: Aceptado (2026-09-30). Completa los ADR 0069 y 0074.

## Contexto

Todos los avisos por WhatsApp salen del número de AgendaUno, y cada uno lo paga la
plataforma (ADR 0069). Se descartó un número propio por negocio: Meta le cobraría a
cada negocio por su cuenta y muchos no querrían pagarlo. Quedaban dos problemas:

- Con «De los negocios a sus clientes» encendido, cualquier negocio podía activar sus
  avisos por WhatsApp. El dueño de la plataforma no podía decidir en cuáles se gasta.
- Los clientes contestan los avisos, y esas respuestas llegaban al número de
  AgendaUno sin que nadie las leyera (ADR 0074). El cliente creía que había avisado
  al negocio, y no tenía cómo pedir que dejaran de escribirle.

## Decisión

### Activación negocio por negocio

- `estudios.whatsapp_habilitado`, apagado por omisión. Ningún negocio lo tiene al
  crearse.
- Solo lo cambia el superadministrador, en la ficha del negocio («WhatsApp con sus
  clientes»), con `PUT /plataforma/estudios/{slug}/whatsapp` (`habilitado`).
  - Activarlo pide confirmación, porque los avisos los paga la plataforma.
  - Desactivarlo no pregunta. Queda en el log de la plataforma, como las demás
    acciones de soporte.
- El negocio no tiene forma de activarlo.
  - Desactivado, para el negocio WhatsApp no existe: no lo ve en sus avisos
    automáticos ni se le ofrece a sus clientes.
  - Activado, el negocio elige qué avisos manda, igual que antes.
- `ClienteWhatsApp::activoPara($estudio)` exige las dos cosas: el uso «De los
  negocios a sus clientes» encendido en la plataforma y el negocio activado. Lo usan:
  - los canales disponibles;
  - el consentimiento al agendar, en «Mi privacidad» y en recepción;
  - la generación y el envío de mensajes.
- Al desactivarlo, lo que tenía en cola se descarta con el motivo «WhatsApp no está
  activo en este negocio».
- En Configuración → «WhatsApp», el uso con los negocios dice en cuántos está
  activado (`negocios_habilitados`).

### Respuesta automática

- Los mensajes que llegan al webhook (`value.messages`) se contestan una vez con texto
  libre. Meta lo permite sin plantilla dentro de las 24 horas del mensaje del
  cliente, y no lo cobra.
- Para saber a qué aviso contesta, `whatsapp_envios.telefono_huella` guarda un HMAC
  del número al que se mandó (con la llave de la app). No se guarda el número ni lo
  que escribió el cliente.
  - El 521… con el que Meta manda los celulares de México se trata como el mismo
    número.
- **Qué se le contesta,** según el último aviso que recibió ese número:
  - **Cliente de un negocio:** el número solo manda avisos; para cambiar o cancelar,
    que le escriba al negocio a su WhatsApp de contacto (el que ya muestra su página)
    o entre a su cuenta. Si ya no quiere los avisos, que responda BAJA.
  - **Dueño:** que su renta y sus avisos están en su panel, con el enlace a «Renta».
  - **Número desconocido:** que se comunique directamente con el negocio.
- **BAJA:** también «dar de baja», «darme de baja», «stop» o «alto», escritos solos,
  sin importar mayúsculas, acentos ni puntuación.
  - Retira el consentimiento en cada negocio que le mandó avisos en el último mes (lo
    que guarda `whatsapp_envios`). Queda en la bitácora del negocio sin actor, con el
    motivo «Lo pidió contestando BAJA por WhatsApp».
  - Si era dueño, deja de recibir por WhatsApp los avisos de la plataforma. El correo
    le sigue llegando.
  - Lo que estaba por salir se descarta. Uno que Meta ya no pudo entregar se queda
    con su motivo.
  - Se le confirma de cuáles negocios ya no recibirá avisos.
- **Topes:**
  - una respuesta por número en el plazo del parámetro de plataforma
    `whatsapp.horas_entre_respuestas` (12 horas por omisión);
  - un mensaje que Meta reintenta se procesa una sola vez;
  - las reacciones y los avisos del sistema no se contestan;
  - BAJA siempre se contesta.
- **Envío:** el webhook responde a Meta al momento y la respuesta sale por la cola
  (`ResponderWhatsApp`, 3 intentos). Sin conexión con Meta no se contesta nada.

## Consecuencias

- El superadministrador decide en qué negocios se gasta en WhatsApp, por ejemplo como
  extra de su plan, y puede apagarlo en todos con un interruptor.
- El cliente que contesta sabe a quién dirigirse, y puede dejar de recibir avisos sin
  tener cuenta.
- Las respuestas siguen sin leerse. Mostrarlas en el panel del negocio sería otro
  trabajo.
- Los negocios que ya usaban WhatsApp quedan desactivados al migrar, hasta que el
  superadministrador los active.
