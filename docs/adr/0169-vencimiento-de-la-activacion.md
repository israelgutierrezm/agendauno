# ADR 0169 — El enlace de activación vence

Estado: Aceptado (2026-10-20).

## Contexto

El dueño que registra su negocio y quien el negocio invita (equipo o clientes)
activan su cuenta con un enlace que llega por correo. Ese enlace servía para siempre:
un correo viejo reenviado, olvidado en otra bandeja o leído por alguien más podía
activar la cuenta meses después. Los enlaces para restablecer la contraseña y para
confirmar un cambio de correo ya vencían; este no.

## Decisión

- El token guarda hasta cuándo sirve: `users.activation_expira_en`.
- Sirve los días del parámetro de plataforma `cuentas.dias_activar_cuenta` (7 por
  omisión, de 1 a 30). Lo cambia el superadmin; no es por negocio, igual que los demás
  enlaces de seguridad.
- Vencido, `POST /activar` responde 422 `ACTIVATION_INVALID` con «El enlace de
  activación ya venció. Pide uno nuevo.». Solo lo dice si el token era correcto; si no,
  el mensaje es el de siempre.
- Pedir otro (`POST /reenviar-activacion`, «Olvidé mi contraseña» con la cuenta
  pendiente o reenviar la invitación) da un enlace nuevo con plazo completo y deja sin
  efecto el anterior.
- La pantalla de activación ofrece «Enviarme un enlace nuevo» cuando el enlace no
  sirvió. La respuesta no dice si la cuenta existe.
- El correo dice cuántos días sirve el enlace.
- Los enlaces que ya estaban enviados al migrar sirven 7 días más.

## Consecuencias

- La limpieza de altas sin activar (ADR 0102) sigue igual: un dueño con el enlace
  vencido pide otro dentro de esos días.
- Activar, dar de baja a la persona o preparar las demos limpian el plazo junto con el
  token.
