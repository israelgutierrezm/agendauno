# ADR 0202 — Wellhub, TotalPass y Fitpass completos

Estado: Aceptado (2026-10-21). Completa los ADR 0148, 0149, 0185 y 0197.

## Contexto

Una revisión contra la documentación pública de cada plataforma (dev.totalpass.com,
developers.wellhub.com y lo que Fitpass y Fitune publican) encontró errores y huecos.
Este ADR junta lo que se cambió para que cada integración quede completa hasta donde
lo permite lo que la plataforma publica. La validación con credenciales reales sigue
pendiente: solo se probó con respuestas simuladas según esa documentación.

## TotalPass

Errores corregidos:

- **El token de confirmación del check-in se cortaba.** Su ejemplo
  (`…/webhook_confirmations/TXIefq…jrw==`) trae base64 con relleno `=`: se tomaba sin el
  `==` y TotalPass habría contestado 404 a todo check-in real. Ahora se toma todo el
  último tramo de la dirección y se acepta `=`, `+`, `.` y `~`.
- **El motivo de un rechazo no se leía.** TotalPass lo manda en `errors[0].source.label`
  (un objeto al confirmar un check-in, una lista al validar un token), no en
  `errors[0].label`. Ahora se leen las tres formas.
- **La ventana para reservar podía caer en el pasado.** Con «en cuanto se programa»
  (0 días) se mandaba la clase menos 60 días; TotalPass rechaza fechas pasadas
  («dates are not before today»). Ahora abre desde ahora (redondeado al minuto) o desde
  los días por adelantado del negocio, y el límite de cancelación nunca queda atrás. Para
  saber si una clase cambió se compara la ventana del negocio, no la hora en que se
  mandó; si las reservas ya cerraron, no se manda ventana y TotalPass conserva la suya.
- **La API de uso del token en pruebas.** Su base es `https://api.totalpass.com/service/v1`
  y en pruebas `https://staging.totalpass.com/api/v1`: la configuración ahora lleva la
  versión (una base vieja sin ella se completa con `/service/v1`).
- **Se le ofrecían lugares de más.** A los lugares libres se les sumaba también a los
  visitantes de TotalPass sin reserva; TotalPass solo descuenta sus reservas. Ahora se
  suman solo sus reservas vivas.

Huecos completados:

- **Validación a mano también con CURP o código de beneficiario** (`type` `curp` o `code`
  en `track_usages`), además del token del día, y los mensajes de `inactive_employee`,
  `check_in_started_on_different_gym` y `gym_not_found`.
- **Sus avisos no van firmados**, así que:
  - el check-in de otro gimnasio (`place.code` distinto del de la integración) se
    descarta, y los datos del visitante solo se guardan si TotalPass confirma el check-in
    (un aviso falso ya no cambia el nombre de nadie);
  - la reserva se comprueba con `GET /partner/slot` y se toman de ahí la clase y la
    persona: tiene que estar viva y ser de una clase publicada aquí. Su ejemplo de aviso
    trae un UUID en `event.id` y su consulta un `eventId` numérico: se acepta cualquiera
    de los dos si es la misma clase.
- **Conciliación de reservas** cada cinco minutos (`agendauno:sincronizar-plataformas`):
  con `GET /partner/slot` por periodo (hasta 30 días), una reserva viva cuyo aviso se
  perdió entra como si hubiera llegado (una sola vez) y un lugar cuya reserva TotalPass
  ya no tiene viva se libera (`CANCELADA_EN_TOTALPASS`).
- **Si no se pudo confirmar a tiempo** (5 minutos), se le pregunta a TotalPass: si la
  dejó viva, el lugar sigue; si no, se libera.
- **Los lugares no bajan de lo que TotalPass ya tiene reservado** (su 422): se leen sus
  `slotsInUse` y se mandan esos.
- **Retirar una clase** primero la deja `INACTIVE` (TotalPass borra sus reservas y la quita
  de su app) y luego la borra.
- **Avisos**: el de check-in se registra con `GET /partner/webhook/get` y `PUT …/update` si
  la sede ya tenía otro; al apagar la integración se quita (`DELETE …/delete/CHECKIN`). Al
  dejar de publicar se quita el de reservas (`DELETE /partner/webhook`) y se apaga la
  confirmación. Al registrarlo se comprueba con `GET /partner/webhook` que quedó el
  nuestro. Con otra llave de sede se vuelven a registrar.
- **Basta la llave de la sede** para activarla (check-in automático y reservas); la llave
  y el código del gimnasio siguen siendo para validar el token a mano.
- Si su sesión (JWT) vence antes de tiempo, se pide otra.

## Wellhub y TotalPass: reintentos

Cuando la plataforma no responde, el aviso se reintenta mientras ella espere (15 minutos
una reserva de Wellhub, 5 una de TotalPass, lo que dure el check-in), cada vez más
espaciado (1, 2, 3… hasta 10 minutos), en lugar de rendirse a los cinco intentos.

## Consecuencias

- La validación con las APIs reales sigue pendiente: hacen falta las credenciales de
  socio de cada plataforma y su ambiente de prueba.
- Queda por confirmar con TotalPass: el host de su admin en producción, los estados de
  una reserva, qué pasa si no se confirma en 5 minutos y si `event.id` del aviso es el
  evento o la ocurrencia.
