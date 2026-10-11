# ADR 0124 — Check-ins de Wellhub y TotalPass: aprobada, rechazada, sin verificar y excepción

Estado: Aceptado (2026-10-11). Plan de mejoras de captación, M10 (bloque 14).

## Contexto

AgendaUno valida con Wellhub y TotalPass la visita de una persona que llega con su plan
de bienestar (solo negocios de clases en México). Hasta ahora, cualquier falla se
contestaba como rechazo: un error de conexión, una caída del proveedor o una llave mal
capturada se veían igual que «el check-in venció». No había salida para recepción cuando
el proveedor no respondía, ni constancia de quién registró la visita.

El plan pide estados de validación aprobada, rechazada y no verificable, sin registrar
como aprobada una visita por un error de conexión. También pide una excepción manual con
quien la autoriza y su evidencia, sin asumir que el proveedor la acepte, y una
comunicación que diga «validación de check-in» cuando ese sea el alcance.

## Alcance de la integración

Lo que hace es validar la visita en la clase. No sincroniza reservas, membresías,
liquidaciones ni pagos con estas plataformas, y la plataforma cubre la clase sin consumir
créditos del negocio.

| Proveedor | Qué valida | Credenciales | Restricción temporal | Qué se guarda |
|---|---|---|---|---|
| Wellhub | Access Control API (`POST /access/v1/validate`) con el Wellhub ID de 13 dígitos | Token de API y Gym ID | El check-in en su app dura 20 minutos; una visita por día | El Wellhub ID y la referencia `wellhub:{gym}:{id}:{día del negocio}` |
| TotalPass | Uso del token del día (`POST /service/v1/track_usages`) | Llave de API, código del gimnasio y, con varios planes, código del plan | El token es del día y de un solo uso | Solo la huella del token (SHA-256), nunca el token |

Las credenciales se guardan cifradas, la web nunca las devuelve y las direcciones son las
fijas de cada proveedor (configuración de la plataforma). El negocio no captura una
dirección propia.

## Decisión

**Tres desenlaces al validar:**

- **Aprobada:** el proveedor la aceptó y se registra `validado` con su referencia. La
  misma visita en la misma clase devuelve el mismo registro. En otra clase del mismo día
  no cuenta dos veces, y el error dice en cuál quedó. Dos validaciones simultáneas del
  mismo código no duplican el registro (índice único).
- **Rechazada:** el proveedor dijo que no (vencido, cancelado, ya usado en otro gimnasio,
  etc.). Se contesta `CHECKIN_INVALID` con su motivo y no se guarda nada.
- **Sin verificar:** no hubo conexión o tiempo, el proveedor devolvió un error de su lado
  (5xx, 429) o no aceptó las credenciales (401, 403). Se contesta `CHECKIN_UNVERIFIABLE`
  (409) con `meta.checkin`, y la visita queda `no_verificable`: visible en la clase,
  nunca aprobada. La plataforma recibe una alerta (`bienestar_no_verificable`, por
  negocio y proveedor) sin llaves ni códigos.

**Reintento:** capturar el mismo código en la misma clase retoma esa visita. Se reconoce
por la huella del código, no por el código. Si ahora se aprueba, pasa a `validado`.

**Excepción manual:** `POST /checkins/{checkin}/excepcion` con `motivo` y `evidencia` (lo
que se comprobó). Solo se admite sobre una visita sin verificar; una rechazada no. Exige
el permiso nuevo `checkins.excepcion`, aparte de registrar entradas. El administrador lo
trae y el propietario lo puede dar en un rol propio. Recepción e instructores no lo
traen. Queda quién la autorizó, cuándo y la evidencia, en el registro y en la bitácora
(`checkin.excepcion`). No se le avisa al proveedor ni se asume que la acepte. Es la
constancia del negocio.

**Constancia:** cada registro guarda quién lo hizo. Encender o apagar la integración o
cambiar una llave va a la bitácora (`integracion.actualizada`) con los nombres de las
llaves cambiadas, nunca sus valores.

**Día del negocio:** la referencia usa la fecha en la zona del negocio (ADR 0099), no la
del servidor.

## Consecuencias

- Una caída del proveedor no bloquea la clase ni inventa validaciones. Recepción ve
  «Sin verificar» y puede reintentar, o alguien con permiso registra la excepción.
- La web dice el alcance en Integraciones y en la clase: «valida el check-in, no
  sincroniza reservas, membresías ni pagos».
- **Pendiente:** verificar con credenciales y entorno autorizados de cada proveedor. Las
  pruebas automáticas usan respuestas simuladas según su documentación pública. La
  comunicación comercial no debe prometer más que la validación de check-in.
