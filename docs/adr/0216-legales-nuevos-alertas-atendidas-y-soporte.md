# ADR 0216 — Legales nuevos, alertas atendidas y reenviar la activación

Estado: Aceptado (2026-10-26). Completa el ADR 0102 (registro y documentos legales) y
el 0132 (operación del superadmin).

## Contexto

La revisión para la V1 encontró tres huecos en el superadmin:

- Publicar una versión nueva del aviso de privacidad o de los términos no le decía nada
  a nadie: los dueños seguían con la versión que aceptaron al registrarse, sin
  constancia de la nueva.
- Las alertas no se podían marcar atendidas. Una que pedía algo (un módulo
  solicitado, un cobro doble) se borraba del historial a los 30 días aunque nadie la
  hubiera visto.
- Un dueño que no encontró el correo de activación o se le venció dependía de que el
  propio dueño pidiera otro: soporte no podía reenviarlo.

## Decisión

- **Legales nuevos.** Al publicar el aviso o los términos (las cookies no se aceptan:
  se eligen en su barra), cada dueño de un negocio operativo recibe un correo
  (`AvisarLegalesNuevos`, en cola). La sesión del negocio dice qué versiones vigentes
  le faltan aceptar (`DocumentosLegales::pendientes`, por su última aceptación). Al
  entrar, su dueño ve un diálogo con los enlaces y las acepta (`POST
  /legales/aceptar`): queda constancia con su correo, la fecha, su IP y su navegador.
  Si lo deja para después, se le vuelve a pedir la próxima vez. Un negocio de
  demostración no las acepta.
- **Alertas atendidas.** Una alerta se marca atendida con quién la atendió; si vuelve a
  pasar, deja de estarlo. Las que piden algo a alguien (`PIDEN_ATENCION`: módulo
  solicitado, Wellhub, cobro doble, devolución, disputa y evento aparcado) no se borran
  del historial hasta que alguien las atiende. Operación cuenta las que faltan.
- **Reenviar la activación** desde la ficha del negocio a un dueño que aún no activa su
  cuenta (con un enlace nuevo). La ficha dice si ya la activó.
