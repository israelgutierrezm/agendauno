# ADR 0148 — Wellhub y TotalPass: visitantes de la plataforma y check-in automático

Estado: Aceptado (2026-10-12). Amplía el ADR 0124.

Pedido: revisar contra la competencia cómo se usan Wellhub y TotalPass y cubrir lo que
falta: «debe existir un apartado para reportes en donde se vea la información del
visitante, ya que no debe verse como un alumno o persona del negocio hasta que se una
por el método tradicional de registro del dueño. TotalPass y Wellhub no son clientes
como tal del dueño sino de TotalPass y Wellhub respectivamente».

## Contexto

AgendaUno validaba la visita a mano (ADR 0124): recepción capturaba el código y se
validaba con la plataforma. La revisión de la competencia (Fitune, Fitco, Buq, EVO y
otros) y de la documentación de las plataformas dejó tres huecos:

- **Check-in automático.** Wellhub exige a un sistema integrado validar de forma
  automática: recibir su aviso de check-in y validarlo sin intervención, además de otra
  vía. TotalPass también avisa de cada check-in y lo que lo valida es confirmarlo.
- **La visita no estaba en la clase.** Validarla no ocupaba un lugar, y quien venía no
  quedaba registrado en ninguna parte.
- **No había dónde ver a quién vino ni sus visitas.**

Las reservas desde la app de la plataforma y la conciliación de pagos quedan fuera de
este ADR.

## Decisión

**Visitantes de la plataforma, no personas del negocio.**

- Quien llega por Wellhub o TotalPass queda como `visitantes_plataforma`. Se identifica
  por el id que da la plataforma: el Wellhub ID o el código de TotalPass.
- Guarda lo que la plataforma manda: nombre, correo, teléfono y producto o plan. También
  su primera y su última visita.
- No es persona, alumno ni miembro:
  - no aparece en el directorio;
  - no cuenta como alumno activo para el cobro de AgendaUno;
  - no consume créditos ni derechos.
- Si el negocio lo registra por la vía de siempre, el reporte dice «Ya es alumno» al
  encontrar el mismo correo, sin unir los registros.

**Su lugar en la clase.**

- `lugares_plataforma` es el lugar que ocupa en una sesión. Ocupa cupo como una reserva
  sin serlo.
- Origen:
  - `visita`: llegó e hizo check-in;
  - `reserva`: para cuando se sincronicen las reservas.
- Ocupan lugar mientras están `reservado` o `asistio`.
- Cuentan en la regla única de cupo (`CupoDeSesionTenant`): en lo ocupado de la clase,
  en lo libre y en el uso del canal de su plataforma. Así lo apartado por canal (R20)
  se consume con sus visitas.
- Su visita no se rechaza aunque la clase esté llena: la persona ya está ahí. Se bloquea
  la clase antes de leer, como cualquier lugar (invariante 8).
- Una visita registrada a mano también ocupa su lugar y queda con su visitante. Wellhub
  da el Wellhub ID; con el token del día de TotalPass no se sabe quién es.

**Check-in automático.**

- Cada negocio tiene una dirección de avisos por plataforma:
  `POST /api/v1/webhooks/bienestar/{estudio}/{proveedor}/{token}`. El token se genera al
  conectar la integración; sin él, el aviso no se acepta (404).
- Wellhub:
  - Su aviso `checkin` trae el Wellhub ID, nombre, correo, teléfono, gimnasio y
    producto.
  - Llega firmado: HMAC-SHA1 del cuerpo, en mayúsculas, en `X-Gympass-Signature`. La
    llave secreta la entrega Wellhub a AgendaUno como sistema integrado
    (`WELLHUB_WEBHOOK_SECRET`); un negocio puede capturar la suya. Sin llave, en
    producción no se acepta.
  - Un aviso de otro gimnasio se descarta.
  - La visita se valida con su Access Control API, igual que a mano: la misma referencia,
    una visita por día.
- TotalPass:
  - Con la llave de AgendaUno como integrador (`TOTALPASS_PARTNER_API_KEY`) y la de la
    sede (`place_api_key`, la captura el negocio), su API nueva da una sesión de 24 horas.
    Al guardar, AgendaUno registra su dirección de avisos (`webhook/create`, `CHECKIN`).
  - Cada aviso trae un token. Confirmarlo
    (`POST {admin}/api/v1/webhook_confirmations/{token}`) es lo que valida la visita.
  - TotalPass no documenta firma: un aviso falso no se puede confirmar, así que no
    aprueba nada.
  - La dirección de confirmación se arma con el host de TotalPass, nunca con la que
    venga en el aviso.
- Bandeja: Wellhub espera respuesta en un segundo.
  - El aviso se guarda en `eventos_plataforma` (una sola vez por plataforma y clave) y se
    contesta al momento.
  - Se procesa después de responder.
  - `agendauno:procesar-plataformas` (cada minuto) retoma lo pendiente.
- Desenlaces:
  - **Aprobada:** se registra la visita (`origen: automatico`). Si recepción ya la había
    registrado a mano, es la misma.
  - **Rechazada** (vencida, no encontrada): no se guarda.
  - **Sin respuesta:** se reintenta mientras el check-in siga vigente (20 minutos en
    Wellhub; en TotalPass hasta que vence). Después queda «sin verificar», nunca
    aprobada, y la plataforma recibe una alerta.
- La clase de una visita automática:
  - la que reservó por la plataforma;
  - si no, la única clase a esa hora (de media hora antes de empezar a que termina);
  - con varias o ninguna, queda sin clase. Recepción la asigna al registrarla en su
    clase, y es la misma visita.
- El aviso llega aunque el negocio esté suspendido, como el de una pasarela: la persona
  ya está en la clase.

**Reportes → Plataformas** (`facturacion.ver`, solo clases):

- `GET /reportes/plataformas`: el resumen de un periodo en días del negocio. Visitas
  (validadas, sin verificar, excepciones, automáticas, sin clase), visitantes, nuevos,
  por plataforma, por día y las clases con más visitas.
- `GET /visitantes-plataforma` (por páginas, con búsqueda) y
  `GET /visitantes-plataforma/{id}` (con sus visitas). Muestran lo que manda la
  plataforma e indican si ya es alumno.

## Consecuencias

- Una visita de plataforma ocupa lugar en la agenda, la recepción, la página pública y
  el motor de reservas, sin ser una reserva ni una persona.
- La alerta y la bitácora siguen sin llaves ni códigos. Del token de TotalPass solo se
  guarda la huella.
- **Pendiente:**
  - Verificar con credenciales y ambiente de prueba de cada plataforma. Las pruebas
    automáticas usan respuestas simuladas según su documentación pública.
  - Confirmar con TotalPass el host de confirmación de producción (documentan el de
    pruebas) y el contenido de su aviso de check-in, que no publican completo.
  - El aviso de Wellhub de cuando un negocio elige a AgendaUno como su sistema
    (Integration Setup API).
  - Las reservas desde la app de cada plataforma (Booking API), que usarán
    `lugares_plataforma` con origen `reserva`.
  - La conciliación con lo que paga cada plataforma.
