# ADR 0138 — Quién atiende a un prospecto y qué pasa si no lo atiende

Estado: Aceptado (2026-10-12). Pedido del dueño sobre Captación:

- un buscador para asignar el responsable entre el equipo, además de «Asignármelo», y
  poder cambiarlo;
- qué sucede si esa persona no lo atiende;
- que cada movimiento se vea en el historial, con un ícono por tipo;
- botones que sí parezcan botones, y el de WhatsApp con su verde y su ícono.

## Contexto

En Prospectos (ADR 0117) el responsable solo se podía tomar («Asignármelo»): no había
cómo pasarlo a alguien más ni quitarlo. Escribirle por WhatsApp o llamarle no quedaba
en ningún lado. Si el responsable no hacía nada, el prospecto se quedaba esperando sin
que nadie se enterara. La historia era una lista de textos iguales. Las acciones del
detalle usaban el botón base sin variante, que se ve como texto.

## Decisión

**Responsable.**

- `GET /prospectos/responsables`: el equipo activo que puede gestionar prospectos.
- En el detalle, un buscador por nombre o correo elige a quién se le pasa; también
  «Asignármelo» y «Quitar responsable».
- Cada cambio queda en la historia con de quién a quién (`detalle.anterior`).
- Funciona en el teléfono: la lista se abre al tocar el campo y se cierra al salir.

**Contacto registrado.** Abrir WhatsApp, llamar o escribir un correo desde el detalle
registra un evento `contacto` con su canal (`POST /prospectos/{p}/contactos`). Cuenta
como primer contacto.

**Si nadie lo atiende.**

- Parámetro por negocio: `captacion.horas_para_atender` (24 por omisión; 0 = no se
  revisa).
- Cada hora (`agendauno:revisar-prospectos-sin-atender`) se revisan los abiertos. Si
  desde que llegó, o desde su última asignación, pasaron esas horas sin atención (nota,
  contacto, cambio de estado, seguimiento, invitación), se marca «sin atender»
  (`prospectos.sin_atender_desde`, migración 000130):
  - queda en su historia;
  - su responsable recibe una tarea; si no tiene, una tarea para el equipo: «asigna a
    alguien»;
  - se avisa con el evento `prospecto.sin_atender`, que el negocio conecta a su
    plantilla para el equipo que gestiona prospectos.
- En la lista hay un indicador «Sin atender» y un filtro con su conteo, para
  reasignarlo con el buscador.
- Atenderlo o reasignarlo le quita la marca; el plazo empieza de nuevo. Una sola marca
  por asignación (sin insistir cada hora).

**Historia.**

- Cada cosa con su ícono neutro: llegó, volvió a escribir, cambio de estado (con el
  punto del estado), responsable, nota (resaltada como nota), seguimiento, contacto por
  canal, invitación, ya es miembro y sin atender.
- El evento «sin atender» va en el color de aviso.

**Botones.**

- Las acciones de Captación usan el botón con borde (`tu-btn-fantasma`).
- Abrir WhatsApp es `BotonWhatsApp`: su verde, su ícono y texto oscuro (el blanco no
  alcanza el contraste sobre el verde).
- Se usa en Prospectos y en el seguimiento de Tareas. Los enlaces de WhatsApp dentro de
  una frase o de una ficha de datos se quedan como enlace.

## Consecuencias

- Nadie se queda esperando en silencio: el responsable tiene su tarea y el equipo se
  entera para reasignarlo.
- La marca se calcula por el orden de la historia (el id del evento), no por la hora: un
  contacto y una reasignación en el mismo segundo no se confunden.
- Los demos marcan sus prospectos sin atender al sembrarse.
