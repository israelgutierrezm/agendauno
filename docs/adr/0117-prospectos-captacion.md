# ADR 0117 — Prospectos: la captación del negocio, aparte de Miembros

Estado: Aceptado (2026-10-10). Plan de mejoras de captación, B01 y C04. Decisión del dueño.
Amplía el ADR 0093 (registro cerrado); retoma las tablas `prospectos` que se quitaron el
2026-09-16 (migración tenant 000038), ahora como captación separada.

## Contexto

«Pedir acceso» en la página de un negocio solo llevaba a su WhatsApp o teléfono: la
plataforma no sabía si la persona recibió respuesta, se inscribió o se perdió. Buq (HYX)
tiene un formulario de contacto propio y Fitune (Fluō) lleva al WhatsApp. El dueño pidió:

- un CRM pequeño de quien todavía no es cliente, **separado de Miembros**, para no mezclar
  a quien el negocio ya aceptó con quien solo busca información;
- que el negocio active o desactive esa captación;
- formularios que se puedan **embeber** en otro sitio, además de compartir por enlace y QR;
- que quien se registre así llegue a Prospectos, pero **no reserve** hasta ser miembro.

## Decisión

### Modelo (base de cada negocio)

- `formularios_captacion` y `campos_formulario_captacion`: el formulario predeterminado (el de
  «Pedir acceso», que se crea solo la primera vez) y los que el negocio arme. Siempre piden
  nombre, correo y la aceptación del aviso; el teléfono, si el negocio lo quiere (y si es
  obligatorio); además, hasta 15 preguntas propias con los tipos de los formularios de
  miembros.
- `prospectos`: nombre, correo, teléfono, lo que le interesaba (sede, actividad, clase o plan,
  solo si es algo público), formulario, origen (formulario o captura manual), fuente (utm),
  estado, motivo y comentario de cierre, responsable, próximo seguimiento, primer contacto,
  la ficha al convertirse, sus respuestas con la etiqueta de entonces y la versión del aviso
  aceptada.
- `prospecto_eventos`: el historial (llegó, volvió a escribir, cambió de estado, responsable,
  nota, seguimiento, invitación, ya es miembro) con su autor.

Un prospecto **no es** una `PersonaTenant`: no aparece en Miembros, no cuenta como alumno
activo (padrón que se cobra) y no puede reservar.

### Reglas

- **Estados.** Abiertos: nuevo, en seguimiento, aprobado e invitación enviada. Cerrados:
  convertido y descartado. Descartar pide un motivo (sin horario, precio, no respondió, no
  cumple, eligió otra, otro) y admite un comentario breve. Un descartado se puede reabrir.
  «Convertido» no se pone a mano.
- **Duplicados.** Un envío con el correo o el teléfono (como número) de un prospecto abierto
  lo actualiza y deja «volvió a escribir»; no abre otro.
- **Captación activable.** Parámetro del negocio `captacion.activa` (sí por omisión). Apagada,
  los formularios responden 404 y «Pedir acceso» vuelve al WhatsApp o teléfono.
- **Privacidad.** En producción no capta sin el aviso de privacidad del negocio publicado;
  guarda la versión aceptada y, si cambió mientras se llenaba, pide revisarlo. reCAPTCHA
  (cerrado en producción sin llave) y límite propio por negocio e IP (`throttle:captacion`).
  Borrar un prospecto borra sus datos y su historial. La plantilla del aviso del negocio ya
  menciona estos datos.
- **Dar acceso (convertir).** Crea o reactiva la ficha con las reglas del alta de miembro
  (`AltaDeMiembroTenant`, extraída del controlador) o la enlaza a la que el equipo elija, y
  si se pide, envía la invitación existente con rol miembro (`InvitarUsuarioTenant`, también
  extraída). Si ya hay una ficha con su correo, el equipo debe elegirla: nunca se enlaza por
  coincidir el correo. Sin invitación queda convertido de una vez; con invitación, al activar
  su cuenta (`ActivacionPropietario`) queda convertido.
- **Permisos** (área «captación»): `prospectos.ver`, `prospectos.gestionar`,
  `prospectos.convertir` (requiere `miembros.gestionar` y `usuarios.invitar`) y
  `prospectos.eliminar`. Administración tiene los cuatro; recepción ve y da seguimiento.
  Alcance por sede: un empleado acotado ve los de sus sedes y los que no dijeron sede.
- **Eventos.** `prospecto.creado` (aviso al equipo con `prospectos.ver`, por plantilla del
  negocio) y `prospecto.convertido`, en el outbox.

### API

- Pública (sin sesión): `GET /captacion` (el predeterminado), `GET
  /captacion/formularios/{formulario}` y `POST /captacion/formularios/{formulario}/envios`.
- Equipo: `/prospectos` (lista con resumen: sin atender, seguimientos vencidos, abiertos),
  `/prospectos/{p}` (detalle con historial), `estado`, `responsable`, `seguimiento`, `notas`,
  `convertir` y borrar; `/captacion/formularios` (lista, crear, editar).
- El escaparate dice `captacion: {activa, formulario}`, y sus próximas clases y planes traen
  su `id` público para pedir lugar o plan.

### Web

- Área **Captación** en el menú, con Prospectos y Formularios de captación, aparte de Miembros.
- «Pedir acceso» en la página del negocio muestra el formulario con lo que la persona eligió
  («Quiero este lugar» en una clase, o un plan), y el WhatsApp como alternativa.
- Página pública `/f/{negocio}/{formulario}`; con `?embed=1`, solo el formulario, que avisa
  su altura a la página que lo contiene.
- nginx permite marcos de cualquier origen solo en `/f/…` (sin `X-Frame-Options`,
  `frame-ancestors *`); son páginas sin sesión. El CI lo revisa.

## Consecuencias

- El negocio sabe quién pidió información, quién la atiende y por qué se perdió, sin abrir
  el registro.
- Pendiente (plan de mejoras): continuar la compra o reserva de lo elegido después de activar
  la cuenta (M04), medir el embudo del negocio (C08) y avisos automáticos (B08).
