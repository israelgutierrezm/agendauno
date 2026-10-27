# ADR 0225 — Push en la web (VAPID)

Estado: Aceptado (2026-10-26). Completa las notificaciones push (ADR 0025, solo la app)
para quien usa la web; decisión del dueño para la V1.

## Contexto

Los avisos push (confirmaciones, recordatorios, cambios, avisos al equipo) solo llegaban
a la app móvil por FCM. Quien usa la web (el equipo en recepción, los clientes que no
instalan la app) no los recibía. El estándar es Web Push con llaves VAPID.

## Decisión

- **Sin dependencias nuevas**: el cifrado del mensaje (RFC 8291, `aes128gcm`) y la firma
  VAPID (RFC 8292, JWT ES256) se hacen con el openssl de PHP (`CifradoWebPush`). Las
  pruebas descifran el mensaje con la llave del «navegador» y verifican la firma.
- **Llaves de la plataforma**: `PUSH_WEB_PUBLICA`, `PUSH_WEB_PRIVADA` y `PUSH_WEB_SUJETO`
  (`agendauno.push_web`), generadas con `agendauno:generar-vapid`. Sin ellas, el push solo
  va a la app; la verificación de producción lo marca como aviso.
- **Suscripciones por negocio**: cada navegador donde un usuario activa los avisos queda
  en `suscripciones_push_web` de ese negocio (`GET|POST|DELETE /mi/push-web`), hasta 10 por
  usuario. Solo direcciones de los servicios de push de los navegadores (FCM, Mozilla,
  Apple, Windows): una suscripción no puede hacer que el servidor llame a otro lado.
- **La misma entrega**: `EntregarPushTenant` manda cada aviso push a los teléfonos con la
  app y a los navegadores suscritos; la suscripción que ya no existe (404/410) o con otras
  llaves (400/403) se olvida. El canal push existe si hay FCM o VAPID.
- **En la web**: el service worker de la app (`/sw.js`) muestra el aviso y, al tocarlo,
  abre la aplicación; «Mi perfil» ofrece «Avisos en este navegador» si el navegador y la
  plataforma pueden.

## Consecuencias

- Cambiar las llaves VAPID deja sin avisos a los navegadores ya suscritos hasta que los
  vuelvan a activar.
- En Safari (iPhone) el push en la web solo funciona con la app agregada a la pantalla de
  inicio; la app de iOS sigue siendo la vía principal.
