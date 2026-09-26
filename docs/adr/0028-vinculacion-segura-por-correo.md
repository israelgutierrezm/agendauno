# ADR 0028 — Vinculación segura por correo (fase 1, punto 1.5)

Estado: Aceptado (2026-09-25). Corrige parte del ADR 0022 ("el correo reactiva").

## Contexto

Escribir un correo no demuestra que sea de quien lo escribe. Con el ADR 0022, el
registro de alumno ligaba al instante la ficha que ya tenía ese correo (con su
membresía, créditos y datos) o reactivaba una cuenta dada de baja **reemplazando su
contraseña**; y la cita pública reactivaba fichas dadas de baja y, con un servicio
individual que se toma con la membresía, podía consumir los créditos del dueño del
correo.

## Decisiones

- **Registro de alumno** (`RegistrarAlumnoTenant`):
  - correo nuevo → la cuenta y su ficha se crean al momento (y entra), como antes;
  - correo que ya es de una ficha o de una cuenta dada de baja → no se liga ni se
    reactiva: se guarda la solicitud (hash del token, contraseña ya cifrada, vence en
    24 h, sirve una vez) y se manda un enlace a ese correo. Al abrirlo
    (`POST /registro-alumno/confirmar`) se liga su historial, se reactiva lo dado de
    baja y entra. Hasta entonces la cuenta anterior no cambia.
  - una ficha que ya es de otra cuenta activa no se toca.
- **Cita pública**: solo servicios que se pagan (uno que se toma con la membresía se
  reserva desde la cuenta); con el correo de una ficha vigente la cita queda en su
  historial sin cambiar sus datos ni darle acceso a nada; a alguien dado de baja no
  se le reactiva (es un cliente nuevo).
- Los correos se comparan sin distinguir mayúsculas.
- El token nunca viaja en la respuesta (solo en el correo), tampoco fuera de
  producción.
- Google (SSO) no cambia: ya exige el correo verificado por Google y no liga cuentas
  dadas de baja.

## Consecuencias

- El alta por recepción (personal autenticado) sigue reactivando por correo: quien
  la hace es del negocio.
- Pendiente (fuera de este punto): enlaces para que el invitado gestione su cita, con
  token de un solo uso y vencimiento.
