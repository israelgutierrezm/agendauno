# ADR 0055 — Rol activo por sesión

Estado: Aceptado (2026-09-28).

## Contexto

Una persona puede tener varios roles en un negocio. Por ejemplo, es instructora y
también alumna, o es dueña y además da clases. Hasta ahora:

- la API sumaba los permisos de **todos** sus roles;
- la web mezclaba en un solo menú lo del alumno, lo del instructor y lo del panel;
- la app elegía la pantalla de inicio con reglas propias.

Con eso, una dueña que entraba «como alumna» seguía pudiendo hacer todo lo del panel,
y el menú no dejaba claro con qué rol trabajaba. Acadion resuelve esto con un rol
activo que el servidor valida.

## Decisión

- **Cada sesión tiene un rol activo**, guardado en el token
  (`personal_access_tokens.rol_activo`). Cada dispositivo tiene el suyo.
- **Al entrar** se usa el de la última vez (`users.ultimo_rol`) si todavía lo tiene;
  si no, el principal (el más amplio).
- **La API solo concede los permisos del rol activo.**
  - `Usuario::puede()` y el alcance miran `rolesVigentes()`, que devuelve el rol
    activo dentro de una sesión.
  - El alcance incluye el instructor acotado y el alcance por sucursal.
  - Conceder o quitar el rol de dueño exige actuar como dueño (`actuaComo`).
  - Fuera de una sesión (tareas programadas, avisos al equipo) cuentan todos los roles.
- **Lo que la persona ES sigue mirando todos sus roles** (`rolesEfectivos()`): si se
  le puede agendar como profesional, si es dueña para las reglas de bajas, a quién se
  avisa.
- **Cambiar de rol**: `PUT /yo/rol-activo {rol}`. Solo acepta roles que la persona
  tiene, cambia el rol de esa sesión y lo recuerda como el último.
  - Si a alguien le quitan el rol con el que trabaja, su sesión sigue con otro de sus
    roles, y el token lo anota.
- **Login y `/yo`** devuelven:
  - `rol`: el activo;
  - `roles`: todos;
  - `roles_disponibles`: `{clave, faceta}`, del rol más amplio al más acotado;
  - `permisos`: los del rol activo.

  La faceta dice qué parte de la app corresponde al rol: `equipo` (el negocio),
  `instructor` (su portal) o `miembro` (su cuenta).
- **Web**:
  - Con más de un rol, tras entrar va a «¿Cómo quieres entrar?», con el último rol
    marcado.
  - En la barra superior hay un botón «Cambiar de rol» (panel lateral).
  - El menú y la pantalla de inicio siguen solo la faceta del rol activo.
- **App**:
  - La misma pantalla al entrar.
  - En Mi perfil, una tarjeta «Cambiar de rol».
  - La pantalla raíz sigue la faceta del rol activo.

## Consecuencias

- Una persona con varios roles tiene un paso más al entrar (elegir), pero ve y puede
  solo lo de ese rol, y el servidor lo garantiza.
- Los roles propios del negocio (siguiente paso) entran en el mismo esquema, cada uno
  con su faceta.
- Las sesiones anteriores a este cambio no tienen rol activo: al resolverse toman el
  de la última vez o el principal. Coincide con el comportamiento anterior, salvo que
  los permisos ya no se suman.
