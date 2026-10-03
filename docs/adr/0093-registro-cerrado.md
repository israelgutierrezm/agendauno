# ADR 0093 — Registro cerrado: las cuentas las crea el negocio

Estado: Aceptado (2026-10-03). Reemplaza el registro público de alumnos del ADR 0028
y el enlace automático por correo con Google.

## Contexto

Cualquiera podía crear su propia cuenta de cliente en un negocio desde la página
pública. Además, «Entrar con Google» enlazaba la cuenta solo por coincidencia de
correo, y de paso activaba invitaciones pendientes.

El usuario pidió una base cerrada: clientes, instructores y administradores existen
solo porque el negocio los da de alta.

## Decisión

### Quién crea qué

- **Dueños:** solo se registran desde la landing, y su cuenta sirve hasta que pasan
  la verificación (enlace de activación). Esto no cambia.
- **Equipo** (administradores, recepción, instructores): lo invita el dueño o quien
  tenga el permiso de usuarios, con los roles que él mismo tiene. Los administradores
  también pueden invitar administradores.
- **Clientes:** el negocio los da de alta (Miembros, importación o recepción) y, si
  deben tener cuenta, los invita. La invitación es la validación del negocio: el
  cliente activa su cuenta con el enlace de su correo. Al activarla, la cuenta queda
  ligada a su ficha, así que su historial, sus créditos y su baja le aplican desde
  ese momento.
- **Se quita** el registro público (`POST /registro-alumno`, su confirmación por
  correo, `RegistrarAlumnoTenant`, la pantalla «Confirma tu registro» y el
  parámetro `cuentas.horas_confirmar_registro`). La tabla `verificaciones_registro`
  queda sin uso; borrarla es una migración aparte.

### Agendar sin cuenta sigue igual

En los negocios de citas, un cliente nuevo puede agendar desde la página pública con
su nombre, correo y teléfono. Eso deja la cita y su ficha en la lista del negocio,
**pero no crea una cuenta**. Para tenerla, el negocio lo invita.

La página pública ya no ofrece «Crear cuenta»: ofrece «Pedir acceso», que explica
que el negocio da las cuentas y muestra el enlace de WhatsApp si lo hay, y «Ya tengo
cuenta». La pantalla de entrar le dice a un cliente sin cuenta que la pida a su
negocio. En la app desaparece la pantalla de registro.

### Google se conecta desde el perfil

- `PUT /yo/google` conecta la cuenta de Google al usuario que inició sesión. Puede ser
  un Gmail distinto de su correo de acceso. Una cuenta de Google sirve para un solo
  usuario del negocio.
- `DELETE /yo/google` la quita.
- `POST /auth/google` solo entra a una cuenta activa con ese Google conectado. No
  enlaza por correo, no activa invitaciones y no crea cuentas. Si el correo coincide
  con una cuenta que aún no lo conectó, pide entrar con contraseña y conectarlo desde
  el perfil.
- El usuario trae `google_conectado`. Mi perfil tiene la sección «Entrar con Google».

## Consecuencias

- Nadie puede crear una cuenta en un negocio sin que el negocio lo autorice. Conocer
  el correo de alguien no da acceso a su ficha.
- Las pruebas crean a los clientes como en la operación real: alta, invitación y
  activación (`alumnoConSesion`).
- La reserva en línea sin cuenta de los negocios de citas no se pierde.
