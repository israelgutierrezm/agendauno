# ADR 0025 — Notificaciones push (Firebase Cloud Messaging)

Estado: Aceptado (2026-09-25).

## Contexto

Los avisos al alumno (confirmación, lugar liberado, recordatorios, clase cancelada,
cobros, renovación) solo salían por correo o a la bandeja de la app. Lo más urgente
—que se liberó un lugar en la lista de espera y tiene minutos para aceptarlo— no
llegaba al teléfono.

## Decisiones

- **Un proyecto de Firebase para la plataforma**, no por negocio: la app es una sola
  para todos los negocios. La API envía con FCM HTTP v1 usando la cuenta de servicio
  del proyecto (`FCM_CREDENTIALS` = ruta al JSON, fuera del repositorio). El token de
  acceso se obtiene firmando un JWT RS256 con `openssl` (sin dependencias nuevas en la
  API) y se reutiliza mientras vive.
- **Push es un canal más** de los mensajes (`CanalComunicacion::Push`): plantillas por
  evento y canal (título en `asunto`, una línea en `cuerpo`), difusiones y el mismo
  relay con reintentos. Si la plataforma no tiene FCM, el canal no se ofrece ni se
  generan mensajes push.
- **Dispositivos por usuario en la BD del negocio** (`dispositivos_push`): la app
  registra su token al iniciar sesión (`POST /mi/dispositivos`) y lo quita al cerrarla
  (`DELETE /mi/dispositivos`, solo el suyo). Un token es de un solo usuario: si otro
  inicia sesión en ese teléfono, pasa a él. Los tokens que FCM ya no reconoce
  (UNREGISTERED, de otro proyecto o inválidos) se borran al enviar.
- Un mensaje push se genera solo para quien tiene la app con sesión; se entrega a
  todos sus teléfonos y cuenta como enviado si llegó a alguno.
- **Avisos push por defecto** (activos, editables): reserva confirmada, lugar liberado
  (el evento `reserva.ofrecida` ahora lleva los datos de la clase), recordatorio 2 h,
  clase o cita cancelada, cobro fallido y renovación próxima.
- **App**: `firebase_core` + `firebase_messaging`. Las opciones del proyecto se pasan
  al compilar con `--dart-define` (sin archivos de Firebase en el repositorio); sin
  ellas la app funciona sin push. Con la app abierta el aviso se muestra dentro de la
  app; en segundo plano lo muestra el sistema.

## Configuración

1. Crear el proyecto de Firebase y registrar la app Android
   (`com.agendauno.app`) y la de iOS.
2. API: Configuración del proyecto → Cuentas de servicio → Generar nueva clave privada;
   guardar el JSON fuera del repositorio y apuntar `FCM_CREDENTIALS` a él.
3. App: compilar con `FIREBASE_API_KEY`, `FIREBASE_APP_ID`, `FIREBASE_SENDER_ID`,
   `FIREBASE_PROJECT_ID` (y `FIREBASE_IOS_BUNDLE_ID` en iOS) de la app registrada.
4. iOS: activar Push Notifications y Background Modes → Remote notifications en Xcode,
   y subir la llave de APNs a Firebase (Cloud Messaging).

## Consecuencias

- La entrega es "al menos una vez": si un teléfono falló por un error pasajero y
  otro no, el mensaje cuenta como enviado; si ninguno lo recibió, se reintenta.
- Las notificaciones al personal (nueva reserva, cancelación) quedan para después:
  los avisos de hoy van a la persona del evento.
