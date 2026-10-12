# ADR 0143 — Medición con GA4 y Meta: consentimiento, destinos y conversiones

Estado: Aceptado (2026-10-12). Pedido: integrar Google Analytics 4 y Meta en AgendaUno y
TurnoUno en dos ámbitos independientes:

- la promoción del SaaS, para captar negocios;
- la promoción de cada negocio, para captar alumnos o clientes.

La atribución propia está en el ADR 0142 y el reporte por campaña en el ADR 0144.

Documentación oficial revisada el 2026-10-12:

- GA4: gtag en SPA, Consent Mode v2, Measurement Protocol y eventos recomendados.
- Meta: píxel, consentimiento, Conversions API, deduplicación, `fbp`/`fbc` y Graph API
  v26.0.

## Decisión

### Dos ámbitos, sin mezclarse

| | SaaS (plataforma) | Cada negocio |
| --- | --- | --- |
| Quién configura | Superadmin → Medición, por producto | Configuración → Integraciones → Medición (`integraciones.configurar` y la función `integraciones` del plan) |
| Destinos | GA4 y Meta de AgendaUno; GA4 y Meta de TurnoUno | Su GA4 y su Meta |
| Dónde se guardan | `destinos_medicion_plataforma` (control plane) | `destinos_medicion` (su base) |
| Páginas | Comerciales del producto y su registro | Su página, su agenda, sus sucursales y enlaces |
| Conversiones | Negocio creado, prueba iniciada, activación operativa, primera suscripción pagada | Solicitud, cuenta activada, compra, devolución, reserva confirmada (+ asistencia y renovación si lo enciende) |

- Una conexión de la plataforma nunca recibe compras de los clientes de un negocio.
- Lo de un negocio no llega a otro: cada uno tiene su base, sus IDs, sus cookies y su
  visitante.

### Consentimiento

- Por ámbito (`lib/consentimiento.ts`): analítica (GA4) y publicidad (Meta y las
  señales de anuncios de Google). Sin elegir, nada opcional se carga ni se manda.
- El aviso solo aparece donde el ámbito tiene destinos encendidos. Después queda
  «Privacidad» para cambiarlo o retirarlo.
- La elección se guarda en el navegador junto con un id de visitante aleatorio
  (32 hex), que solo existe si se autorizó algo. Cada cambio se manda al servidor
  (`POST /medicion/consentimiento`): lo retirado se borra de lo que ya se registró, y lo
  pendiente se vuelve a revisar al mandarlo.
- Retirar la autorización apaga GA4 (`ga-disable-{id}`), revoca el píxel
  (`fbq('consent','revoke')`) y borra sus cookies.
- Consent Mode v2 empieza todo en «denied» y se actualiza con lo elegido. Las etiquetas
  se cargan solo después de elegir (modo básico).

### Etiquetas del navegador (`lib/etiquetas.ts`)

- Solo dos scripts oficiales (gtag.js y fbevents.js) con IDs validados. No se puede
  pegar JavaScript.
- Una vista de página por navegación, desde el router:
  - GA4 con `send_page_view: false`, y la guía pide apagar «Cambios de página según el
    historial»;
  - Meta con `disablePushState` y sin eventos automáticos (`autoConfig` falso).
- La ruta se manda sin query, salvo `utm_*` y `gclid` validados. En el SaaS se agrupa
  como la medición interna (sin slugs ni tokens).
- No se mide en el panel, la cuenta del cliente, el superadmin, un widget ni una vista
  previa: ahí GA4 queda desactivado.
- En los negocios:
  - GA4 usa un prefijo de cookie propio (`n_{slug}`) y cookies solo de su dominio;
  - **el píxel de Meta no se carga**: guarda `_fbp` en el dominio padre
    (`*.agendauno.mx`), compartido con otros negocios y con la plataforma, y Meta no
    documenta cómo evitarlo desde el código. Meta recibe las conversiones del negocio
    desde el servidor.
- Sin correspondencia avanzada: `fbq('init', id)` sin datos de la persona, y la guía
  pide dejar apagada la automática.

### Conversiones del servidor

- Cada resultado sale de lo que pasó en el sistema, no de un clic ni de volver de la
  pasarela.
- Se crea pendiente, una vez por destino (`id_evento` único), y se manda aparte
  (`agendauno:enviar-conversiones`, cada minuto). Registrar, pagar o reservar nunca
  esperan a Google ni a Meta.
- El SaaS detecta, desde que el destino se encendió y dentro de la ventana del
  proveedor:
  - negocio creado: registrado y aprovisionado;
  - prueba iniciada: solo si el plan tiene prueba, y sin monto;
  - activación operativa: `primera_operacion_en`;
  - primera suscripción pagada: el primer cargo realmente pagado, sin timbres, sin
    «sin cargo» y sin monto cero.

  La activación de un miembro o instructor invitado no es un negocio nuevo. Las demos no
  se mandan.
- Nombres por proveedor:

  | Resultado | GA4 | Meta |
  | --- | --- | --- |
  | negocio creado | `sign_up` | `CompleteRegistration` |
  | prueba iniciada | `prueba_iniciada` | `StartTrial` |
  | activación operativa | `negocio_activado` | `ActivacionOperativa` (personalizado, `system_generated`) |
  | primera suscripción | `purchase` (`transaction_id` = ulid del cargo, `customer_type: new`) | `Purchase` (`order_id`) |

- Antes de mandar se revisa todo otra vez (`EnviarConversionMedicion`):
  - entorno: fuera de producción se omite, salvo `MEDICION_ENVIAR_FUERA_DE_PRODUCCION`;
  - GA4: autorización de analítica y `client_id` real de su navegador;
  - Meta: autorización de publicidad y agente del navegador (en la web);
  - ventana: GA4 72 h, Meta 7 días.

  Lo que no se manda queda **omitido** con su motivo. Nada se inventa.
- **GA4 (Measurement Protocol):**
  - `timestamp_micros` es la hora real del evento;
  - la sesión se manda solo si el evento ocurrió a menos de 30 minutos de capturarla;
  - `consent.ad_user_data` y `consent.ad_personalization` van según la autorización de
    publicidad;
  - el secreto va en la URL, como pide GA4, y se oculta en cualquier error guardado.
- **Meta (Conversions API v26.0, configurable):**
  - `event_time` en segundos y el mismo `event_id` que el píxel (por ejemplo,
    `negocio_creado-{ulid}`);
  - `user_data` con IP, agente, `fbp`, `fbc` y `external_id` = SHA-256 del visitante;
    sin correos ni teléfonos;
  - el token va en el cuerpo, nunca en la URL;
  - `fbc` sale de la cookie o se arma con el `fbclid` del último toque
    (`fb.1.{ms}.{fbclid}`).

### Conversiones de cada negocio

- Salen de sus eventos de dominio por el outbox (`RegistrarConversionesMedicion`):
  después de que la operación quedó confirmada, sin consultar a Google ni a Meta
  mientras se registra, se paga o se reserva.
- El listener solo anota la conversión pendiente: es idempotente (una por destino e
  `id_evento`) y nunca hace fallar el despacho. `agendauno:enviar-conversiones` las
  manda negocio por negocio, cada uno en su base y aislado.

| Evento de dominio | Resultado | GA4 | Meta | `id_evento` |
| --- | --- | --- | --- | --- |
| `prospecto.creado` | solicitud (un reenvío no es otra) | `generate_lead` (`lead_source: formulario`) | `Lead` | `solicitud-{prospecto}` |
| `cuenta.creada` | cuenta activada (la invitación sola no) | `sign_up` (`method: invitacion`) | `CompleteRegistration` | `cuenta_activada-{persona}` |
| `orden.pagada` sin renovación, con monto | compra (`customer_type` new o returning) | `purchase` (`transaction_id` = orden) | `Purchase` | `compra-{orden}` |
| `pago.reembolsado` (aplicado) o `pago.anulado` | devolución, solo si su compra ya se mandó a ese destino | `refund` | (Meta no tiene) | `devolucion-{reembolso}` / `anulacion-{pago}` |
| `reserva.confirmada` | la primera de cada persona | `reserva_confirmada` (`tipo`) | `Schedule` | `reserva-{reserva}` |
| `orden.pagada` de renovación | si lo enciende | `renovacion_pagada` | `RenovacionPagada` | `renovacion-{orden}` |
| `asistencia.marcada` presente | primera asistencia, si lo enciende | `primera_asistencia` | `PrimeraAsistencia` | `primera_asistencia-{persona}` |

- **Contexto.** El de la solicitud o de la orden; si no tiene, el más reciente de la
  persona o el de su solicitud convertida. Así la campaña sigue de la solicitud a la
  cuenta, la compra y la reserva.
- **Dónde pasó.**
  - una compra en la pasarela en línea es `website`; en el mostrador,
    `physical_store`;
  - lo demás es `website` si su navegador dejó página y agente, y si no,
    `system_generated`.
- **Lo que no se manda:**
  - una compra pagada con las llaves de prueba de la pasarela (`pago_de_prueba`);
  - lo de un negocio en demostración (`negocio_demo`);
  - lo que pasó antes de encender el destino;
  - sin la función en su plan, lo pendiente espera y vence con la ventana.
- **Negocios de salud:** `sensible` quita los nombres de los productos (GA4) y los
  `content_ids` (Meta), y deja solo eventos estándar o sin parámetros que revelen el
  servicio.
- **Navegador (solo GA4, en su página):** `page_view`; `view_item_list` y
  `select_item` de sus planes (en salud sin nombres); `begin_checkout` al pagar una cita
  (sin el nombre del servicio); `contacto` por WhatsApp o teléfono.
  - Lo que la página mide antes de saber si hay destinos espera y se manda al saberlo.
  - `InitiateCheckout` no se manda: el píxel no se carga en el negocio y no hay un
    «pago iniciado» confirmado en el servidor.
  - La clase de prueba de un negocio es una compra; `StartTrial` es solo la prueba del
    SaaS.
- **Asistencia y renovación** siempre se quedan en el seguimiento interno. Mandarlas es
  decisión del negocio, por destino (`eventos_extra`).
- Su página pide su propio permiso («Este negocio usa medición…») y lo manda a
  `POST /app/{slug}/medicion/consentimiento`. Es otro visitante: no el de la plataforma
  ni el de otro negocio.
- Los cambios de su configuración quedan en su auditoría, sin el secreto («Se cambió el
  secreto»).

### Duplicados: estrategia explícita

- GA4:
  - el navegador manda vistas e intención (`inicio_registro`, `contacto`…);
  - los resultados confirmados (`sign_up`, `generate_lead`, `purchase`, `refund`) los
    manda **solo el servidor**. No hay dos fuentes del mismo evento.
- Meta:
  - los resultados que pasan en la página (el registro) se mandan desde el navegador y
    desde el servidor con el mismo `event_name` + `event_id`, y Meta los deduplica
    (48 h);
  - las compras, solo desde el servidor.
- Recargas y reintentos:
  - cada conversión existe una vez por destino;
  - un 4xx no se reintenta;
  - 5xx, 429, los códigos temporales de Meta (1, 2, 4, 17, 341, 368) o «no conectó»
    se reintentan con espera (1, 5, 15, 60 y 180 minutos; máximo 6 intentos);
  - un corte **después** de mandar solo se reintenta si el proveedor deduplica (GA4
    `purchase` por `transaction_id`). Lo demás queda «error: sin respuesta» para no
    contarlo dos veces;
  - el token de Meta vencido (190) no se reintenta y avisa al superadmin.

### Estados y diagnóstico

- Estados: `pendiente`, `enviado` (aceptado por el proveedor, **no** quiere decir
  atribuido), `error` (con su próximo intento o sin él) y `omitido` (con motivo).
- Cada destino muestra:
  - descripción y campos (el secreto se escribe y no se vuelve a mostrar);
  - estado, último envío y último error;
  - encendido;
  - «Probar conexión»: en GA4, valida con `/debug/mp/collect` y manda `prueba_conexion`
    con `debug_mode`, que se ve en DebugView; en Meta, lee el píxel con el token y, con
    código de prueba, manda un evento a «Eventos de prueba»;
  - la guía de configuración.
- Encender un destino pide su ID, su secreto y confirmar que el aviso de privacidad
  vigente ya informa esta medición. Desconectar borra ID y secreto, y lo pendiente
  queda omitido (`destino_desconectado`).

### Privacidad y seguridad

- En los eventos no van nombres, correos, teléfonos, expedientes, respuestas de
  formularios, datos de salud ni de menores. Solo ulids, montos, moneda y, fuera de
  los negocios de salud, el nombre del plan.
- Secretos cifrados (`encrypted`), ocultos en las respuestas y fuera de la bitácora
  (sus claves contienen «secret»). Los mensajes de error pasan por el `Tachador`.
- Los identificadores del navegador se borran a los 120 días
  (`MEDICION_RETENCION_DIAS`). Las preferencias y la campaña se quedan.

## Consecuencias

- Migración del control plane `2026_10_12_000200`: destinos y envíos, más las
  preferencias e identificadores en `contextos_medicion_plataforma`.
- Migración de negocio 000134: `destinos_medicion`, `envios_medicion` y las
  preferencias e identificadores en `contextos_medicion`.
- `GET /medicion/{producto}` (IDs públicos), `POST /medicion/consentimiento` y
  `/plataforma/medicion` (superadmin).
- **Requisitos del aviso de privacidad, antes de encender un destino.** No se capturan
  ni se inventan aquí los datos del responsable, y esto no garantiza el cumplimiento:
  lo revisa quien es responsable del tratamiento. El borrador actual dice que no se usan
  cookies de publicidad, así que hay que actualizarlo y publicar una versión nueva. Debe
  informar:
  - que se usan Google Analytics y Meta, para qué (analítica y medición de campañas) y
    que son terceros que reciben los datos;
  - qué se manda: identificadores del navegador, IP y agente con publicidad, páginas,
    resultados y montos. Nunca nombre, correo ni teléfono;
  - que es opcional, cómo aceptarlo, rechazarlo o retirarlo («Privacidad»), y que
    retirarlo borra las cookies;
  - cuánto tiempo se conservan los identificadores (120 días aquí, más lo que guarde
    cada proveedor), y las transferencias internacionales a Google y Meta.

  Cada negocio necesita lo mismo en su propio aviso.
- No se incluye: leer reportes externos, importar gasto, administrar anuncios,
  audiencias avanzadas, widgets nuevos ni medición de las apps nativas.
- Validación real pendiente: con credenciales reales de GA4 (DebugView y Tiempo real) y
  de Meta (Eventos de prueba). Aquí se probó con proveedores simulados (`Http::fake`) y
  en el navegador sin enviar a destinos reales.
