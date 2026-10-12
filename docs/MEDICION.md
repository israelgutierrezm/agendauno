# Medición con GA4 y Meta

Guía de operación. Las decisiones están en los ADR 0142 (atribución), 0143 (GA4, Meta,
consentimiento y conversiones) y 0144 (reporte por campaña).

## Dos ámbitos, sin mezclarse

| | Promoción del SaaS | Promoción de cada negocio |
| --- | --- | --- |
| Para qué | Captar negocios que contraten AgendaUno o TurnoUno | Captar alumnos o clientes de un negocio |
| Dónde se configura | Superadmin → **Medición** (cada producto aparte) | Configuración → Integraciones → **Medición** (`integraciones.configurar`, plan con integraciones) |
| Destinos | Una propiedad de GA4 y un píxel de Meta por producto | Su propio GA4 y su propio Meta |
| Etiquetas en el navegador | GA4 y píxel de Meta en las páginas comerciales y el registro | Solo GA4 en su página, su agenda y sus enlaces, con cookies propias (`n_{slug}_ga`) |
| Conversiones del servidor | Negocio creado, prueba iniciada, activación operativa, primera suscripción pagada | Solicitud, cuenta activada, compra, devolución, primera reserva (+ asistencia y renovación si las enciende) |
| Reporte interno | Superadmin → Embudo → Por campaña | Reportes → Embudo → Por campaña |

En los negocios, el píxel de Meta no se carga en el navegador. Guarda su cookie `_fbp` en
el dominio padre (`*.agendauno.mx`), compartido con otros negocios y con la plataforma,
y Meta no documenta cómo evitarlo desde el código. Meta recibe las conversiones del
negocio desde el servidor.

## Consentimiento

- Las páginas con destinos muestran «Tu privacidad»: aceptar, solo lo necesario o
  elegir **analítica** (GA4) y **publicidad** (Meta y las señales de anuncios de Google).
- Sin elegir no se carga nada. Después queda el botón **Privacidad** para cambiarlo.
- Retirar el permiso:
  - apaga GA4, revoca el píxel y borra sus cookies;
  - lo avisa al servidor, que borra los identificadores de lo ya registrado con ese
    navegador;
  - lo pendiente se vuelve a revisar antes de mandarlo.
- Cada producto y cada negocio piden su propio permiso.

## Qué se manda y cuándo

| Ámbito | Resultado | Cuándo | GA4 | Meta |
| --- | --- | --- | --- | --- |
| SaaS | Visita | Cada navegación en páginas comerciales y registro | `page_view` (navegador) | `PageView` (navegador) |
| SaaS | Inicio de registro | Al empezar el registro | `inicio_registro` (navegador) | `InicioRegistro` (navegador) |
| SaaS | Negocio creado | Registrado y aprovisionado | `sign_up` (servidor) | `CompleteRegistration` (navegador y servidor, mismo `event_id`) |
| SaaS | Prueba iniciada | Solo si su plan tiene prueba; sin monto | `prueba_iniciada` (servidor) | `StartTrial` (navegador y servidor) |
| SaaS | Activación operativa | Su primera operación real con un cliente | `negocio_activado` (servidor) | `ActivacionOperativa` (servidor) |
| SaaS | Primera suscripción pagada | Primer cargo realmente pagado (sin timbres ni ceros) | `purchase` (servidor) | `Purchase` (servidor) |
| Negocio | Visita | Su página, su agenda, sus sucursales y enlaces | `page_view` (navegador) | — |
| Negocio | Ver planes / elegir uno | Su página muestra sus planes; toca uno | `view_item_list` / `select_item` (navegador) | — |
| Negocio | Inicio de pago | Agendar y pagar en línea (sin el nombre del servicio) | `begin_checkout` (navegador) | — |
| Negocio | Contacto | Clic en WhatsApp o teléfono: **no** es un prospecto | `contacto` (navegador) | — |
| Negocio | Solicitud | Prospecto nuevo de su formulario (un reenvío no cuenta) | `generate_lead` (servidor) | `Lead` (servidor) |
| Negocio | Cuenta activada | El invitado activó su cuenta (la invitación sola no cuenta) | `sign_up` (servidor) | `CompleteRegistration` (servidor) |
| Negocio | Compra | Orden realmente pagada, con monto; nueva o recurrente | `purchase` (servidor) | `Purchase` (servidor) |
| Negocio | Devolución | Reembolso aplicado o cobro anulado, si su compra se mandó | `refund` (servidor) | — |
| Negocio | Primera reserva | La primera reserva confirmada de cada persona | `reserva_confirmada` (servidor) | `Schedule` (servidor) |
| Negocio | Primera asistencia | Solo si el negocio la enciende | `primera_asistencia` | `PrimeraAsistencia` |
| Negocio | Renovación | Solo si el negocio la enciende | `renovacion_pagada` | `RenovacionPagada` |

Lo que **no** se manda:

- `InitiateCheckout` de Meta en los negocios: su píxel no se carga y el servidor no
  tiene un «pago iniciado» confirmado. Del SaaS tampoco, porque el pago de la
  suscripción es dentro del panel, donde no se mide;
- `StartTrial` por la clase de prueba de un negocio: esa clase es una compra (`purchase`
  / `Purchase`). `StartTrial` es solo la prueba del SaaS;

- un pago pendiente, el regreso de la pasarela o un pago con llaves de prueba;
- una prueba gratuita como venta;
- nombres, correos, teléfonos, respuestas de formularios, expedientes, datos de salud o
  de menores;
- en negocios de salud, los nombres de servicios;
- las demos;
- nada fuera de producción (salvo `MEDICION_ENVIAR_FUERA_DE_PRODUCCION=true` en un
  entorno de pruebas);
- lo que pasó antes de encender el destino.

## Atribución propia

- Primer origen y último toque por ámbito: un toque es un UTM (`utm_source` o
  `utm_campaign`), `gclid`, `fbclid` o un enlace desde otro sitio.
- Se acredita el último toque vigente. Por omisión vale 30 días y el primer origen se
  recuerda 90; los dos son parámetros del negocio, y los de la plataforma se aplican al
  registro.
- Se guarda con la solicitud, la orden, la cita y el registro del negocio. Sin toque
  vigente, «sin atribución».
- No equivale a la atribución de Google ni a la de Meta.

## Estados de un envío

- **Pendiente**: se manda en el siguiente minuto (`agendauno:enviar-conversiones`).
- **Aceptado por el proveedor**: lo recibió. No quiere decir que lo atribuyó a un
  anuncio.
- **Con error**:
  - se reintenta 1, 5, 15, 60 y 180 minutos después (hasta 6 intentos) si volver a
    mandarlo no lo duplica;
  - «el token o el secreto no sirve» no se reintenta y avisa al superadmin;
  - «sin respuesta» tampoco se reintenta, para no contarlo dos veces.
- **Omitido**, con su motivo:
  - la persona no lo autorizó;
  - sin identificador de su navegador;
  - pasó el plazo del proveedor (72 h GA4, 7 días Meta);
  - entorno de pruebas;
  - negocio de demostración;
  - pago de prueba;
  - destino apagado o desconectado.

## Aviso de privacidad (antes de encender)

El borrador del aviso de la plataforma dice que no se usan cookies de publicidad: hay que
actualizarlo y publicar una versión nueva en el superadmin → Legales antes de encender un
destino. Encender pide confirmarlo. Debe informar:

- qué terceros reciben datos (Google, Meta) y para qué (analítica, medición de
  campañas);
- qué se manda: identificadores del navegador; IP y agente, con publicidad; páginas,
  resultados y montos. Nunca nombre, correo ni teléfono;
- que es opcional y cómo aceptarlo, rechazarlo o retirarlo («Privacidad»);
- cuánto se conservan los identificadores (120 días aquí, más lo que guarde cada
  proveedor) y las transferencias internacionales.

Cada negocio necesita lo mismo en su propio aviso. Los datos del responsable y la
revisión legal son de quien es responsable del tratamiento: esto no garantiza el
cumplimiento.

## Validar con cuentas reales

Las pruebas automáticas usan proveedores simulados. Mientras no se haga esta validación,
**no está validado contra Google ni Meta**.

1. Crea (o usa) una propiedad de GA4 de prueba y un píxel con su código de «Eventos de
   prueba». No se crean cuentas ni campañas desde el sistema.
2. En un entorno de pruebas con HTTPS, `MEDICION_ENVIAR_FUERA_DE_PRODUCCION=true` y el
   scheduler corriendo (`agendauno:enviar-conversiones` cada minuto):
   - en GA4 → flujo web → Medición mejorada → Vistas de página → avanzado, apaga
     «Cambios de página según el historial del navegador»;
   - en Meta, deja apagada la correspondencia avanzada automática.
3. Captura los IDs y secretos (superadmin o Integraciones), confirma el aviso, enciende y
   usa **Probar conexión**:
   - en GA4 debe aparecer `prueba_conexion` en DebugView;
   - en Meta, el evento en «Eventos de prueba».
4. **Solicitud de punta a punta:**
   - abre la página de un negocio con `?utm_source=prueba&utm_campaign=v1&fbclid=PRUEBA123`
     y acepta todo;
   - comprueba en DebugView que hay una sola `page_view` por navegación;
   - deja tus datos en «Pedir acceso»;
   - en un minuto: `generate_lead` en GA4, `Lead` en «Eventos de prueba» (con `fbc`) y el
     envío «Aceptado» en Integraciones → Medición;
   - el prospecto dice «Campaña: v1 · prueba».
5. **Compra:**
   - convierte e invita al prospecto; al activar su cuenta llega `sign_up` /
     `CompleteRegistration`;
   - compra un plan desde su cuenta y págalo en caja o con la pasarela en vivo:
     `purchase` / `Purchase` con el ulid de la orden como `transaction_id` / `order_id`;
   - una orden pendiente no manda nada, y pagada con llaves de prueba queda omitida.
6. **Devolución:** reembolsa ese pago y llega `refund` a GA4 con la misma transacción. En
   Meta no hay devolución.
7. **Retirar el permiso:** «Privacidad» → solo lo necesario. Las cookies se borran y lo
   pendiente queda «Omitido: la persona no lo autorizó».
8. Repite lo del SaaS con un registro de negocio (VERIFICACION-V1, caso 13). Al terminar,
   borra el código de eventos de prueba.

## Pendiente

- La validación real con Google y Meta (arriba).
- Las cuentas y credenciales de GA4 y Meta de AgendaUno y de TurnoUno.
- La versión del aviso de privacidad que informe esta medición.
- No incluido en este pedido: leer reportes externos, importar gasto, administrar
  anuncios, audiencias avanzadas, widgets nuevos y la medición de las apps nativas.
