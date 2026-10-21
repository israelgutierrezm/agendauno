# ADR 0199 — Google Calendar y Outlook en las dos direcciones

Estado: Aceptado (2026-10-20). Sin validar con Google ni con Microsoft: hacen falta los
clientes OAuth de la plataforma.

## Contexto

El calendario iCal personal («Mi perfil → Calendario») solo va en una dirección:
Google, Apple u Outlook leen las clases y citas cuando quieren (Google tarda horas). El profesional que también tiene compromisos fuera del negocio
tenía que repetirlos a mano como bloqueos de su agenda. Fitune y Atom sincronizan en
las dos direcciones con Google Calendar.

## Decisión

- **Cada quien que atiende conecta su cuenta** de Google o de Microsoft (Outlook,
  Microsoft 365) en Mi perfil. Solo quien tiene la faceta de quien imparte
  (`esProfesional()`); un cliente sigue con su enlace iCal.
- **Clientes OAuth de la plataforma** (`CALENDARIOS_GOOGLE_*`, `CALENDARIOS_MICROSOFT_*`)
  con una sola vuelta: `{APP_URL}/api/v1/calendarios/{proveedor}/vuelta`. Sin ellos no
  se ofrece.
- **Permisos mínimos.** Google: `calendar.app.created` (solo los calendarios que crea la
  app) y `calendar.freebusy` (si está ocupado, no qué tiene). Microsoft no tiene uno
  equivalente: `Calendars.ReadWrite`, y de su calendario principal solo se pide
  `start`, `end` y `showAs`.
- **Hacia allá.** Sus clases y citas programadas (de ayer a `CALENDARIOS_DIAS`, 60 por
  omisión) van a un calendario aparte con el nombre del negocio, no al principal. Cada
  evento lleva el nombre del servicio, la sede y el negocio: ningún dato de clientes.
  Una huella de lo mandado evita reenviar lo que no cambió; lo cancelado, lo que pasó a
  otra persona o salió del periodo se borra allá (lo que solo quedó atrás se queda como
  historial). Si lo borraron allá, se vuelve a crear.
- **Hacia acá.** Lo ocupado de su calendario principal en ese periodo se vuelve
  bloqueos de su agenda (`bloqueos_agenda.calendario_externo_id`, motivo «Ocupado en
  Google Calendar» u «Ocupado en Outlook»). En cada pasada se reemplazan: se crean los
  nuevos y se borran los que ya no están. El verificador de agenda ya los respeta, así
  que no se le agenda encima. En la agenda se ven «De su calendario» y no se quitan
  aquí (422): volverían en la siguiente pasada.
- **Conectar sin canjear en la vuelta.** El estado del OAuth va cifrado con la llave de
  la app (negocio, persona, proveedor y 15 minutos de vigencia). La vuelta no canjea
  nada: lleva el código a Mi perfil en la web del negocio, y la web lo confirma
  (`POST /yo/calendarios/{p}/confirmar`) con la sesión. El código solo se canjea si el
  estado es de quien tiene la sesión: una persona no puede conectar su cuenta a la de
  otra.
- **Llaves cifradas** en la base del negocio (cast `encrypted`), nunca en respuestas.
  Si Google o Microsoft retiran el permiso (`invalid_grant`), la conexión queda en
  `reconectar` y deja de sincronizarse.
- **`agendauno:sincronizar-calendarios`** cada 10 minutos en cada negocio (también en
  uno suspendido, como las plataformas: su agenda sigue). Un candado por conexión evita
  dos pasadas a la vez (la del programador y «Sincronizar ahora»).
- **Bitácora**: `calendario_externo.conectado` y `calendario_externo.desconectado`.
  Desconectar borra el calendario del negocio de su cuenta y los bloqueos que trajo.

## Consecuencias

- Mientras la plataforma no registre los clientes OAuth, Mi perfil no lo muestra.
- Google pide verificar la app para los permisos de calendario antes de abrirla a
  cualquier cuenta; mientras tanto solo sirve con cuentas de prueba.
- ~~Es por sondeo (10 minutos)~~: desde el ajuste del 2026-10-21, Google y Microsoft
  avisan al momento (ver abajo); la pasada de cada 10 minutos queda de respaldo.

## Ajuste (2026-10-21): avisos al momento

- **Suscripción por conexión** al calendario principal:
  - **Google:** un canal `events.watch` de 7 días. Google no renueva canales: se abre
    uno nuevo y se cierra el anterior (`channels/stop`). Para el canal se pide además
    `calendar.events.freebusy`, que sigue siendo solo disponibilidad.
    - Una conexión hecha antes de este cambio no tiene ese permiso: no abre canal y
      sigue con la pasada hasta que se vuelva a conectar.
  - **Microsoft:** una suscripción de Graph a `me/calendar/events` (el principal, no
    el que llena la app) de 3 días; Graph deja hasta 7. Se alarga con `PATCH`; si
    Microsoft ya no la tiene, se crea otra.
- **Ciclo de vida:**
  - La abre o la renueva la pasada cuando falta menos de un día para que venza.
  - Si no se pudo, se reintenta a las 6 horas, y mientras tanto sigue la pasada.
  - Al desconectar, se cierra.
- **Avisos** en `/api/v1/webhooks/calendarios/{google|microsoft}/{negocio}`, sin
  sesión:
  - Cada conexión tiene su secreto (cifrado), que regresa en cada aviso (`token` del
    canal de Google, `clientState` de Microsoft).
  - Si el canal y el secreto son de una conexión activa, se sincroniza en cola
    (`SincronizarCalendarioExterno`, una por conexión en 60 segundos: Google avisa de
    cada evento).
  - Se contesta al momento, y siempre igual: así no se revela qué existe.
  - La validación de Microsoft (`validationToken`) se devuelve en texto.
- **Solo con HTTPS:** sin una `APP_URL` con HTTPS (en desarrollo) no se abren
  suscripciones; queda la pasada.
- **La pasada de cada 10 minutos sigue:** publica nuestras clases y citas en su
  calendario, renueva las suscripciones y cubre un aviso perdido.
- Sin validar con Google ni con Microsoft: probado con respuestas simuladas.
