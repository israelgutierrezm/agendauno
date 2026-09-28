# ADR 0057 — Roles propios del negocio

Estado: Aceptado (2026-09-28). Se apoya en el ADR 0055 (rol activo).

## Contexto

Los permisos del negocio venían de cinco roles fijos en el código: dueño, admin,
recepción, instructor y miembro. Un negocio necesita armar los suyos, por ejemplo
«Coordinación», que ve clientes y agenda y administra al equipo. Quien administra
roles no debe poder darse ni dar a otros más de lo que tiene.

Se descartó reinstalar Spatie Permission. Hay una base por negocio y Spatie guarda
en caché los permisos de forma global, así que habría que aislar esa caché por
negocio a mano. El sistema propio ya decide todo con `puede()`.

## Decisión

- **Tabla `roles` en la base de cada negocio**: `clave` (fija, `rol_…`), `nombre`,
  `faceta` y `permisos`. Los roles de sistema siguen en el código.
  - `RolesTenant` une los de sistema con los propios. Los ordena así: dueño, admin,
    recepción, los propios, instructor y miembro.
  - Resuelve permisos, faceta y rol principal para cualquier rol.
  - Guarda lo leído por negocio mientras dura la petición o el trabajo.
- **En V1, los roles propios son del equipo** (faceta `equipo`). Instructor y miembro
  tienen reglas propias: se le agenda como profesional, portal del alumno, alcance a
  sus clases. Quien imparte y además coordina tiene los dos roles y cambia entre ellos
  (rol activo).
- **Catálogo de permisos agrupado por área** (`CatalogoDePermisosTenant::catalogo()`),
  con el permiso nuevo `roles.gestionar`. Una prueba exige que todo permiso usado en
  una ruta o en un rol de sistema esté en el catálogo.
- **Nadie da más de lo que tiene.** Se compara siempre con los permisos de su rol
  activo:
  - un rol solo lleva permisos que tiene quien lo arma;
  - no se edita ni se borra un rol con permisos que uno no tiene, ni uno que uno
    mismo tiene. Así nadie se sube de nivel ni se deja fuera;
  - no se borra un rol que alguien tiene asignado;
  - al invitar o al cambiar los roles de alguien, cada rol que se da o se quita debe
    caber en lo que uno tiene. El rol de dueño conserva su regla propia (ADR 0055).
  - Los roles de sistema no se editan.
- **API**:
  - `GET/POST /roles` y `PUT/DELETE /roles/{rol}`, con el permiso `roles.gestionar`;
  - el listado trae el catálogo, `mis_permisos`, cuántas personas tienen cada rol y
    `puede_cambiar`;
  - `roles_disponibles` y `GET /usuarios` (`roles_detalle`) traen el nombre de los
    roles propios.
- **Web**: la página «Roles y permisos» en Configuración.
  - Separa los roles propios de los del sistema; cada rol puede desplegar sus
    permisos.
  - Tiene un editor lateral: los permisos que uno no tiene aparecen deshabilitados.
  - En Equipo se asignan los roles propios con su nombre.
- **Bitácora**: `rol.creado`, `rol.actualizado` y `rol.eliminado`.

## Consecuencias

- El dueño puede delegar: arma un rol con `roles.gestionar` y la persona que lo tenga
  crea roles solo dentro de sus permisos.
- Los roles por sede (asignaciones de personal) siguen aceptando solo los roles de
  sistema.
- Los permisos siguen siendo «ver» y «gestionar» por área. Separar «editar» de
  «eliminar» es un cambio posterior del catálogo que no toca este esquema.
