# ADR 0077 — «Eliminar» aparte de «gestionar»

Estado: Aceptado (2026-09-30). Completa el catálogo del ADR 0057.

## Contexto

Los permisos eran «ver» y «gestionar» por área, y «gestionar» incluía borrar y dar
de baja. Un negocio no podía dar a alguien permiso de editar sin darle también el
de borrar: por ejemplo, que recepción corrija los datos de un alumno pero no lo dé
de baja.

## Decisión

- **Solo donde se borra o se da de baja** hay un permiso `*.eliminar`, que pide el
  `*.gestionar` de su área (requisitos del ADR 0057):

  | Permiso | Qué permite |
  |---|---|
  | `miembros.eliminar` | Dar de baja alumnos o clientes (`DELETE /miembros/{persona}`) |
  | `usuarios.eliminar` | Dar de baja a alguien del equipo (`DELETE /usuarios/{usuario}`) |
  | `agenda.eliminar` | Borrar horarios que se repiten, días especiales, bloqueos, recursos y reglas de cupo por canal |
  | `productos.eliminar` | Archivar planes y paquetes (`archivado: true` en `PUT /productos/{producto}`) |
  | `promociones.eliminar` | Borrar promociones |
  | `comunicaciones.eliminar` | Borrar plantillas de mensajes |
  | `automatizaciones.eliminar` | Borrar automatizaciones |

- **Lo que no cambia:**
  - Reactivar (alumnos, equipo o planes archivados) sigue en «gestionar».
  - Cancelar una clase, una cita o una reserva es operación del día: sigue en
    «gestionar».
  - Quitar una foto o un logo es editar.
  - Roles, integraciones y pasarelas ya son permisos de administración.
- **Roles de sistema:**
  - El admin tiene todos los permisos de eliminar.
  - Recepción ya no da de baja clientes. Los edita, les vende y les reserva igual
    que antes.
  - El instructor no cambia.
- **Roles propios existentes:** una migración les agrega el permiso de eliminar de
  cada área que ya gestionaban, para que nadie pierda lo que hoy puede hacer. Al
  editarlos, el dueño puede quitarlo.
- **Web:** cada botón de borrar, dar de baja o archivar solo aparece con su permiso.
  En el editor de roles, los permisos nuevos salen con su nombre en cada área.

## Consecuencias

- Un negocio puede delegar la edición sin arriesgar bajas, y la bitácora sigue
  registrando quién borró qué.
- Una ruta nueva que borre debe exigir el permiso de eliminar de su área. La prueba
  del catálogo sigue exigiendo que todo permiso usado esté en el catálogo.
- La app no tiene acciones de borrar, así que no cambia.
