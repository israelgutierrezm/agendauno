# ADR 0190 — Plantillas de WhatsApp de cada negocio

Estado: Aceptado (2026-10-20). Completa los ADR 0069 y 0186. Sin validar con Meta.

## Contexto

Los avisos por WhatsApp salen del número de la plataforma con plantillas fijas,
aprobadas una vez para todos (ADR 0069). El negocio solo enciende o apaga cada aviso.
Las campañas no iban por WhatsApp: Meta solo deja iniciar una conversación con una
plantilla aprobada, y no había forma de que el negocio escribiera la suya.

El dueño pidió primero completar las plantillas por negocio y su costo por mensaje; la
bandeja de WhatsApp e Instagram, después. Meta cobra por mensaje según la categoría:
para México, uno de marketing cuesta varias veces lo que uno de utilidad (según
fuentes de terceros; hay que confirmarlo con la tabla oficial).

## Decisión

- **Cada negocio escribe sus plantillas** para campañas.
  - Lleva un nombre (solo para el negocio), una categoría (utilidad o marketing), el
    texto (hasta 1,024 caracteres) y un pie opcional.
  - El texto usa dos marcadores: `{{persona_nombre}}` y `{{negocio}}`. El de negocio
    es obligatorio: todo sale del número de la plataforma y quien lo recibe debe saber
    quién le escribe.
  - Como pide Meta, ningún marcador abre ni cierra el texto y ninguno se repite. Se
    rechaza al guardar, no al mandar.
- **Viven en el control plane** (`plantillas_whatsapp_negocio`), como el número: son
  plantillas de la cuenta de WhatsApp Business de la plataforma (`waba_id`, que el
  superadmin carga junto al número). Su nombre en Meta es `n{negocio}_{ulid}`: único, y
  uno nuevo al corregir una rechazada (Meta no deja reusarlo).
- **Estados**: borrador → en revisión → aprobada, rechazada (con lo que dijo Meta) o
  pausada. Archivada ya no se usa y se borra de Meta.
  - Solo se edita un borrador o una rechazada.
  - La plataforma pregunta a Meta cómo van: `agendauno:revisar-plantillas-whatsapp`,
    cada 15 minutos para las que están en revisión y una vez al día para las aprobadas
    o pausadas. El negocio también puede preguntar desde el panel.
- **Campañas por WhatsApp.** El canal aparece cuando el negocio tiene WhatsApp
  (ADR 0083) y la plataforma puede mandar plantillas.
  - La campaña va con una plantilla aprobada, no con texto libre.
  - Solo llega a quien aceptó sus avisos por WhatsApp y tiene un celular válido.
  - Como toda campaña, respeta a quien no quiere promociones y la regla de no repetirla
    en 24 horas.
  - Antes de enviar dice a cuántos llega, a cuántos no (`sin_whatsapp`) y cuánto gasta.
- **Costo por mensaje**: cada mensaje gasta del saldo de WhatsApp (ADR 0186) según la
  categoría: utilidad, uno; marketing, `whatsapp.mensajes_por_marketing` (parámetro de
  la plataforma, 5 por omisión). El panel muestra lo que gasta y cuesta cada categoría,
  si la plataforma lo cobra.
- **Bitácora**: `whatsapp.plantilla_enviada` y `whatsapp.plantilla_archivada`.

## Consecuencias

- Mientras la plataforma no cargue su cuenta de WhatsApp Business, nada cambia para
  los negocios.
- Meta puede reclasificar una plantilla de utilidad como marketing. Se cobra según la
  categoría que guardó el negocio: hay que revisarlo cuando se confirme la tarifa.
- La bandeja de WhatsApp e Instagram (contestar desde el panel) queda para después.
  Pide que la plataforma sea Tech Provider de Meta y que cada negocio conecte su propia
  cuenta.
- Sin validar con Meta: probado con respuestas simuladas de la Graph API.
