# ADR 0078 — Roles propios de quien imparte

Estado: Aceptado (2026-09-30). Amplía el ADR 0057, que dejaba los roles propios en
la faceta del equipo.

## Contexto

Los roles propios (ADR 0057) solo podían ser del equipo. Quien imparte clases o
atiende citas tenía siempre el rol de sistema «instructor», con sus permisos fijos.
Un negocio no podía tener, por ejemplo, un «Instructor en formación» que ve sus
clases pero no pasa asistencia, o un «Barbero senior» que además cobra.

Además, «es profesional» se decidía por el nombre del rol (`'instructor'`) en
agendar, reprogramar, disponibilidad, la página pública, las listas del equipo y el
alcance a sus propias sesiones.

## Decisión

- **Faceta al crear el rol:** `POST /roles` acepta `faceta`: `equipo` (por omisión)
  o `instructor`.
  - La faceta no cambia al editarlo. Cambiarla movería a alguien entre el panel y
    el portal, y quitaría a un profesional de la agenda. Para eso se crea otro rol.
  - Un rol de quien imparte necesita `agenda.ver`, porque su portal muestra sus
    clases o citas. Lo demás lo elige el negocio, con las mismas reglas del ADR
    0057: nadie da lo que no tiene y cada permiso lleva sus requisitos.
- **Profesional por faceta, no por nombre:**
  - `RolesTenant::clavesConFaceta()` y `tieneFaceta()` resuelven los roles de sistema
    y los propios.
  - `Usuario::esProfesional()` dice si alguno de sus roles es de quien imparte, y
    `Usuario::profesionales()` es la consulta de quienes lo son.
  - Los usan agendar y reprogramar citas, la disponibilidad y las opciones de cita,
    la página pública y la lista de instructores.
- **Alcance:** `AccesoSesionTenant::esInstructorAcotado()` acota a quien actúa con
  un rol de quien imparte y sin uno del equipo. En una sesión cuenta solo el rol
  activo, así que con su rol de instructor, propio o de sistema, solo ve y opera sus
  clases o citas.
- **Orden de los roles:** dueño, admin, recepción, los propios del equipo,
  instructor, los propios de quien imparte y miembro.
- **Web:** «Nuevo rol» pregunta «Para quién es»: «Del equipo» o «De quien imparte».
  - Sin «Ver la agenda», lo explica y no deja guardar.
  - La lista marca los roles de quien imparte.
  - El portal y el menú ya se decidían por la faceta del rol activo, así que no
    cambian. La app tampoco.

## Consecuencias

- El negocio arma variantes de quien imparte con más o menos permisos, sin perder la
  agenda ni el alcance a lo suyo.
- Código nuevo que pregunte si alguien es profesional usa `esProfesional()` o
  `profesionales()`, nunca el nombre del rol.
- Las asignaciones por sede (R19) siguen aceptando solo roles de sistema.
